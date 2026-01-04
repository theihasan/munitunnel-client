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

    public function handle()
    {
        $url = (string) $this->option('url');
        $subdomain = (string) $this->option('subdomain');
        if ($subdomain === '') {
            $subdomain = 'abc';
        }

        $proxyUrl = (string) $this->option('proxy-url');
        if ($proxyUrl === '') {
            $proxyUrl = $this->resolveProxyUrl($url);
        }

        $this->info('Connecting to ' . $url);
        $this->info('Using subdomain: ' . $subdomain);
        $this->info('Using proxy URL: ' . $proxyUrl);

        connect($url)->then(
            function (WebSocket $conn) use ($subdomain, $proxyUrl) {
                $this->info("Connected!\n");

                $conn->send(json_encode([
                    'event' => 'register',
                    'data' => [
                        "subdomain" => $subdomain
                    ],
                ]));

                $conn->on('message', function ($msg) use ($proxyUrl) {
                    $data = json_decode((string)$msg, true);
                    if (!is_array($data)) {
                        return;
                    }

                    $this->info("Received control message:");
                    $this->line((string)$msg);

                    if ($data['event'] ?? null === 'createProxy') {
                        $requestId = $data['data']['requestId'] ?? null;
                        if (is_string($requestId) && $requestId !== '') {
                            $this->info("Proxy requested for requestId: {$requestId}");

                            connect($proxyUrl)->then(function (WebSocket $proxyConn) use ($requestId) {
                                $this->info("Proxy connected for requestId: {$requestId}");

                                $proxyConn->on('message', function ($msg) use ($requestId, $proxyConn) {
                                    $payload = json_decode((string)$msg, true);

                                    $this->info("Proxy received payload for {$requestId}:");
                                    $this->line((string)$msg);

                                    if (!is_array($payload)) return;

                                    if (($payload['event'] ?? null) === 'httpRequest') {
                                        $method = strtoupper((string) ($payload['data']['method'] ?? 'GET'));
                                        $path = $payload['data']['path'] ?? '/';
                                        $query = $payload['data']['query'] ?? '';
                                        $body = $payload['data']['body'] ?? '';
                                        $fullPath = $query !== '' ? "{$path}?{$query}" : $path;
                                        $bodyLength = is_string($body) ? strlen($body) : 0;

                                        $proxyConn->send(json_encode([
                                            'event' => 'httpResponse',
                                            'data' => [
                                                'requestId' => $requestId,
                                                'status' => 200,
                                                'body' => "Hello from client! method={$method} path={$fullPath} body_len={$bodyLength}",
                                            ],
                                        ]));

                                        $this->info("Sent httpResponse for {$requestId}");
                                    }
                                });

                                $proxyConn->send(json_encode([
                                    'event' => 'registerProxy',
                                    'data' => ['requestId' => $requestId],
                                ]));

                                $this->info("Proxy registered for requestId: {$requestId}");
                            });

                        }
                    }
                });

            },
            function (\Exception $e) {
                $this->error("Connection error: " . $e->getMessage());
                Loop::stop();
            }
        );


        Loop::run();
        return 0;
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
