<?php

namespace App\Commands;

use Illuminate\Console\Command;
use Psr\Http\Message\ResponseInterface;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\Http\Browser;
use function Ratchet\Client\connect;

class ConnectCommand extends Command
{
    protected $signature = 'connect {--url=ws://127.0.0.1:8081} {--subdomain=abc} {--proxy-url=} {--token=} {--upstream=}';
    protected $description = 'Connect to the control WebSocket server';

    private string $controlUrl = '';
    private string $proxyUrl = '';
    private string $subdomain = '';
    private string $authToken = '';
    private string $upstreamUrl = '';
    private ?Browser $httpClient = null;
    private ?WebSocket $controlConn = null;
    private ?WebSocket $proxyConn = null;
    private int $controlAttempts = 0;
    private int $proxyAttempts = 0;
    private $controlReconnectTimer = null;
    private $proxyReconnectTimer = null;
    private int $reconnectMinDelay = 1;
    private int $reconnectMaxDelay = 30;

    public function handle()
    {
        $this->controlUrl = (string) $this->option('url');
        $this->subdomain = (string) $this->option('subdomain');
        if ($this->subdomain === '') {
            $this->subdomain = 'abc';
        }

        $proxyUrl = (string) $this->option('proxy-url');
        if ($proxyUrl === '') {
            $proxyUrl = $this->resolveProxyUrl($this->controlUrl);
        }
        $this->proxyUrl = $proxyUrl;

        $token = (string) $this->option('token');
        if ($token === '') {
            $envToken = getenv('MUNITUNNEL_AUTH_TOKEN');
            $token = is_string($envToken) ? $envToken : '';
        }
        $this->authToken = $token;

        $upstreamUrl = (string) $this->option('upstream');
        if ($upstreamUrl === '') {
            $envUpstream = getenv('MUNITUNNEL_UPSTREAM_URL');
            $upstreamUrl = is_string($envUpstream) ? $envUpstream : '';
        }
        if ($upstreamUrl === '') {
            $upstreamUrl = 'http://127.0.0.1:3000';
        }
        $this->upstreamUrl = rtrim($upstreamUrl, '/');

        $this->info('Connecting to ' . $this->controlUrl);
        $this->info('Using subdomain: ' . $this->subdomain);
        $this->info('Using proxy URL: ' . $this->proxyUrl);
        $this->info('Using upstream: ' . $this->upstreamUrl);
        if ($this->authToken !== '') {
            $this->info('Auth token: set');
        }

        $this->connectControl();
        $this->connectProxy();

        Loop::run();
        return 0;
    }

    private function connectControl(): void
    {
        if ($this->controlConn !== null) {
            return;
        }

        connect($this->controlUrl)->then(
            function (WebSocket $conn) {
                $this->controlConn = $conn;
                $this->controlAttempts = 0;
                $this->info("Control connected.\n");

                $payload = [
                    'event' => 'register',
                    'data' => [
                        'subdomain' => $this->subdomain,
                    ],
                ];
                if ($this->authToken !== '') {
                    $payload['data']['token'] = $this->authToken;
                }
                $conn->send(json_encode($payload));

                $conn->on('message', function ($msg) {
                    $data = json_decode((string) $msg, true);
                    if (!is_array($data)) {
                        return;
                    }

                    $event = $data['event'] ?? null;
                    if ($event === 'registered') {
                        $publicUrl = $data['data']['publicUrl'] ?? '';
                        if ($publicUrl !== '') {
                            $this->info('Control registered: ' . $publicUrl);
                        }
                        return;
                    }

                    if ($event === 'createProxy') {
                        $this->info('Ignoring createProxy in persistent mode.');
                    }
                });

                $conn->on('close', function () {
                    $this->info('Control connection closed.');
                    $this->controlConn = null;
                    $this->scheduleReconnect('control');
                });
            },
            function (\Exception $e) {
                $this->error('Control connection error: ' . $e->getMessage());
                $this->controlConn = null;
                $this->scheduleReconnect('control');
            }
        );
    }

    private function connectProxy(): void
    {
        if ($this->proxyConn !== null) {
            return;
        }

        connect($this->proxyUrl)->then(
            function (WebSocket $conn) {
                $this->proxyConn = $conn;
                $this->proxyAttempts = 0;
                $this->info("Proxy connected.\n");

                $payload = [
                    'event' => 'registerProxy',
                    'data' => [
                        'subdomain' => $this->subdomain,
                    ],
                ];
                if ($this->authToken !== '') {
                    $payload['data']['token'] = $this->authToken;
                }
                $conn->send(json_encode($payload));

                $conn->on('message', function ($msg) use ($conn) {
                    $payload = json_decode((string) $msg, true);
                    if (!is_array($payload)) {
                        return;
                    }

                    $event = $payload['event'] ?? null;
                    if ($event === 'ping') {
                        $conn->send(json_encode([
                            'event' => 'pong',
                            'data' => [
                                'ts' => $payload['data']['ts'] ?? null,
                            ],
                        ]));
                        return;
                    }

                    if ($event !== 'httpRequest') {
                        return;
                    }

                    $this->handleHttpRequest($conn, $payload);
                });

                $conn->on('close', function () {
                    $this->info('Proxy connection closed.');
                    $this->proxyConn = null;
                    $this->scheduleReconnect('proxy');
                });
            },
            function (\Exception $e) {
                $this->error('Proxy connection error: ' . $e->getMessage());
                $this->proxyConn = null;
                $this->scheduleReconnect('proxy');
            }
        );
    }

    private function scheduleReconnect(string $type): void
    {
        if ($type === 'control') {
            if ($this->controlReconnectTimer) {
                return;
            }

            $delay = $this->nextDelay(++$this->controlAttempts);
            $this->info("Reconnecting control in {$delay}s...");
            $this->controlReconnectTimer = Loop::addTimer($delay, function () {
                $this->controlReconnectTimer = null;
                $this->connectControl();
            });
            return;
        }

        if ($this->proxyReconnectTimer) {
            return;
        }

        $delay = $this->nextDelay(++$this->proxyAttempts);
        $this->info("Reconnecting proxy in {$delay}s...");
        $this->proxyReconnectTimer = Loop::addTimer($delay, function () {
            $this->proxyReconnectTimer = null;
            $this->connectProxy();
        });
    }

    private function nextDelay(int $attempts): int
    {
        $delay = $this->reconnectMinDelay * (2 ** max(0, $attempts - 1));
        return (int) min($this->reconnectMaxDelay, $delay);
    }

    private function resolveProxyUrl(string $controlUrl): string
    {
        $parts = parse_url($controlUrl);
        if (!is_array($parts)) {
            return 'ws://127.0.0.1:8082';
        }

        $scheme = $parts['scheme'] ?? 'ws';
        $host = $parts['host'] ?? '127.0.0.1';
        $port = isset($parts['port']) ? ((int) $parts['port'] + 1) : 8082;

        return "{$scheme}://{$host}:{$port}";
    }

    private function handleHttpRequest(WebSocket $conn, array $payload): void
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return;
        }

        $requestId = $data['requestId'] ?? null;
        if (!is_string($requestId) || $requestId === '') {
            return;
        }

        $method = strtoupper((string) ($data['method'] ?? 'GET'));
        $path = (string) ($data['path'] ?? '/');
        $query = (string) ($data['query'] ?? '');
        $headers = $data['headers'] ?? [];
        $body = $data['body'] ?? '';

        $targetUrl = $this->buildUpstreamUrl($path, $query);
        if ($targetUrl === '') {
            $this->sendHttpResponse($conn, $requestId, 502, 'Invalid upstream URL.');
            return;
        }

        $filteredHeaders = $this->filterHeaders(is_array($headers) ? $headers : []);
        $payloadBody = is_string($body) ? $body : '';

        $this->httpClient()->request($method, $targetUrl, $filteredHeaders, $payloadBody)->then(
            function (ResponseInterface $response) use ($conn, $requestId) {
                $status = $response->getStatusCode();
                $body = (string) $response->getBody();
                $this->sendHttpResponse($conn, $requestId, $status, $body);
            },
            function (\Exception $e) use ($conn, $requestId) {
                $this->sendHttpResponse($conn, $requestId, 502, 'Upstream error: ' . $e->getMessage());
            }
        );
    }

    private function httpClient(): Browser
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Browser(null, Loop::get());
        }

        return $this->httpClient;
    }

    private function buildUpstreamUrl(string $path, string $query): string
    {
        $parts = parse_url($this->upstreamUrl);
        if (!is_array($parts) || ($parts['host'] ?? '') === '' || ($parts['scheme'] ?? '') === '') {
            return '';
        }

        $path = $path === '' ? '/' : $path;
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        $url = $this->upstreamUrl . $path;
        if ($query !== '') {
            $url .= '?' . $query;
        }

        return $url;
    }

    private function filterHeaders(array $headers): array
    {
        $blocked = [
            'connection' => true,
            'content-length' => true,
            'host' => true,
            'keep-alive' => true,
            'proxy-connection' => true,
            'transfer-encoding' => true,
        ];

        $filtered = [];
        $forwardedHost = '';

        foreach ($headers as $name => $values) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            $lower = strtolower($name);
            if ($lower === 'host') {
                if (is_array($values) && isset($values[0]) && is_scalar($values[0])) {
                    $forwardedHost = (string) $values[0];
                } elseif (is_scalar($values)) {
                    $forwardedHost = (string) $values;
                }
            }

            if (isset($blocked[$lower])) {
                continue;
            }

            if (is_array($values)) {
                $cleanValues = [];
                foreach ($values as $value) {
                    if (is_scalar($value)) {
                        $cleanValues[] = (string) $value;
                    }
                }
                if ($cleanValues !== []) {
                    $filtered[$name] = $cleanValues;
                }
                continue;
            }

            if (is_scalar($values)) {
                $filtered[$name] = (string) $values;
            }
        }

        if ($forwardedHost !== '' && !isset($filtered['X-Forwarded-Host'])) {
            $filtered['X-Forwarded-Host'] = $forwardedHost;
        }

        return $filtered;
    }

    private function sendHttpResponse(WebSocket $conn, string $requestId, int $status, string $body): void
    {
        $conn->send(json_encode([
            'event' => 'httpResponse',
            'data' => [
                'requestId' => $requestId,
                'status' => $status,
                'body' => $body,
            ],
        ]));

        $this->info("Sent httpResponse for {$requestId}");
    }
}
