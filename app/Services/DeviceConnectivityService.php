<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceManagementSetting;

class DeviceConnectivityService
{
    public function __construct(private readonly DeviceEventService $events) {}

    public function markStaleDevicesOffline(): array
    {
        $changed = 0;
        Device::query()->where('enrollment_status', 'enrolled')
            ->where('connectivity_status', '!=', 'offline')
            ->chunkById(100, function ($devices) use (&$changed): void {
                foreach ($devices as $device) {
                    $minutes = DeviceManagementSetting::withoutGlobalScopes()->where('company_id', $device->company_id)->value('heartbeat_timeout_minutes') ?? config('devices.heartbeat_timeout_minutes');
                    $threshold = now()->subMinutes($minutes);
                    if ($device->last_heartbeat_at && $device->last_heartbeat_at->gte($threshold)) {
                        continue;
                    }
                    $device->update(['connectivity_status' => 'offline', 'connection_status' => 'OFFLINE']);
                    $this->events->record($device, 'device_offline', 'high', ['code' => 'heartbeat_timeout'], true);
                    $changed++;
                }
            });

        return ['devices_marked_offline' => $changed];
    }
}
