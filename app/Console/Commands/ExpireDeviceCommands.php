<?php

namespace App\Console\Commands;

use App\Services\DeviceCommandService;
use Illuminate\Console\Command;

class ExpireDeviceCommands extends Command
{
    protected $signature = 'device-commands:expire';

    protected $description = 'Expire device commands that passed their delivery deadline';

    public function handle(DeviceCommandService $commands): int
    {
        $this->components->info('Expired '.$commands->expireAll().' command(s).');

        return self::SUCCESS;
    }
}
