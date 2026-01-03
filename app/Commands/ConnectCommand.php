<?php

namespace App\Commands;

use Illuminate\Console\Command;

class ConnectCommand extends Command
{
    protected $signature = 'connect';

    protected $description = 'Command description';

    public function handle()
    {
        $this->info('Connecting...');
    }
}
