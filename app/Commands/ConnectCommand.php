<?php

namespace App\Commands;

use Illuminate\Console\Command;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use function Ratchet\Client\connect;

class ConnectCommand extends Command
{
    protected $signature = 'connect {--url=ws://127.0.0.1:8081}';
    protected $description = 'Connect to the control WebSocket server';

    public function handle()
    {
        $url = (string) $this->option('url');
        $this->info('Connecting to ' . $url);

        connect($url)->then(
            function (WebSocket $conn) {
                $this->info("Connected!\n");

                $conn->send(json_encode([
                    'event' => 'register',
                    'data' => [
                        "subdomain" => "abc"
                    ],
                ]));

                $conn->on('message', function ($msg) {
                    $this->info("Received control message:");
                    $this->line((string)$msg);
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
}
