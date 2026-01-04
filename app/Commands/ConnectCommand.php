<?php

namespace App\Commands;

use Illuminate\Console\Command;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use function Ratchet\Client\connect;

class ConnectCommand extends Command
{
    protected $signature = 'connect {--url=ws://127.0.0.1:8081} {--subdomain=abc} {--proxy-url=}';
    protected $description = 'Connect to the control WebSocket server';

    private string $controlUrl = '';
    private string $proxyUrl = '';
    private string $subdomain = '';
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

        $this->info('Connecting to ' . $this->controlUrl);
        $this->info('Using subdomain: ' . $this->subdomain);
        $this->info('Using proxy URL: ' . $this->proxyUrl);

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

                $conn->send(json_encode([
                    'event' => 'register',
                    'data' => [
                        'subdomain' => $this->subdomain,
                    ],
                ]));

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

                $conn->send(json_encode([
                    'event' => 'registerProxy',
                    'data' => [
                        'subdomain' => $this->subdomain,
                    ],
                ]));

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

                    $requestId = $payload['data']['requestId'] ?? null;
                    if (!is_string($requestId) || $requestId === '') {
                        return;
                    }

                    $method = strtoupper((string) ($payload['data']['method'] ?? 'GET'));
                    $path = $payload['data']['path'] ?? '/';
                    $query = $payload['data']['query'] ?? '';
                    $body = $payload['data']['body'] ?? '';
                    $fullPath = $query !== '' ? "{$path}?{$query}" : $path;
                    $bodyLength = is_string($body) ? strlen($body) : 0;

                    $conn->send(json_encode([
                        'event' => 'httpResponse',
                        'data' => [
                            'requestId' => $requestId,
                            'status' => 200,
                            'body' => "Hello from client! method={$method} path={$fullPath} body_len={$bodyLength}",
                        ],
                    ]));

                    $this->info("Sent httpResponse for {$requestId}");
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
}
