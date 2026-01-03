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

        connect($url)->then(function (WebSocket $conn) {
            $this->info("Connected!\n");
            $conn->send(json_encode([
                'event' => 'ping',
            ]));

            $conn->on('message', function ($msg) use ($conn) {
                $this->info("Received: {$msg}\n");
                $conn->close();
            });

            $conn->on('close', function ($code) use ($conn) {
                $this->info("Connection closed ({$code})\n");
                Loop::stop();
            });

        });

        function(\Exception $e) {
            $this->info("An error occurred: {$e->getMessage()}\n");
            Loop::stop();
        };

        Loop::run();
        return 0;
    }
}
