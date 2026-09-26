<?php

namespace App\Console\Commands;

use App\Models\DeviceLocation;
use Illuminate\Console\Command;

class PurgeOldDeviceLocations extends Command
{
    protected $signature = 'devices:purge-old-locations';

    protected $description = 'Delete precise device locations beyond configured retention';

    public function handle(): int
    {
        $days = max(1, (int) config('devices.location_retention_days'));
        $count = DeviceLocation::where('captured_at', '<', now()->subDays($days))->delete();
        $this->components->info("Purged {$count} location record(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
