<?php

namespace App\Services;

use App\Models\DeviceEvent;
use App\Models\SystemAlert;

class AlertService
{
    public function syncDeviceEvents(): int
    {
        $count = 0;
        DeviceEvent::query()->whereIn('severity', ['high', 'critical'])->whereNull('acknowledged_at')->each(function (DeviceEvent $event) use (&$count) {
            $alert = SystemAlert::firstOrCreate(['company_id' => $event->company_id, 'deduplication_key' => 'device-event:'.$event->id], ['alert_type' => $event->event_type, 'severity' => $event->severity, 'title' => str($event->event_type)->replace('_', ' ')->title(), 'message' => 'A managed device reported a high-priority security or compliance event.', 'entity_type' => 'device', 'entity_id' => $event->device_id, 'status' => 'open']);
            if ($alert->wasRecentlyCreated) {
                $count++;
            }
        });

        return $count;
    }
}
