<?php

namespace App\Console\Commands;

use App\Services\DeviceConnectivityService;
use Illuminate\Console\Command;

class MarkDevicesOffline extends Command
{
    protected $signature = 'devices:mark-offline';

    protected $description = 'Mark enrolled devices with stale heartbeats offline without changing control state';

    public function handle(DeviceConnectivityService $connectivity): int
    {
        $result = $connectivity->markStaleDevicesOffline();
        $this->info("Marked {$result['devices_marked_offline']} device(s) offline.");

        return self::SUCCESS;
    }
}
