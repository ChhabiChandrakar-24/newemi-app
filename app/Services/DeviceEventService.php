<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceEvent;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class DeviceEventService
{
    public function record(
        Device $device,
        string $eventType,
        string $severity,
        ?array $payload = null,
        bool $deduplicateUnacknowledged = false,
    ): DeviceEvent {
        if (! in_array($eventType, config('devices.event_types'), true)) {
            throw ValidationException::withMessages(['event_type' => ['Unsupported device event type.']]);
        }

        if ($deduplicateUnacknowledged) {
            $existing = $device->events()->where('event_type', $eventType)->whereNull('acknowledged_at')->latest('id')->first();
            if ($existing) {
                return $existing;
            }
        }

        $event = $device->events()->create([
            'company_id' => $device->company_id,
            'event_type' => $eventType,
            'severity' => $severity,
            'event_time' => now(),
            'payload' => $payload === null ? null : Arr::only($payload, [
                'code', 'detail', 'component', 'policy', 'previous', 'current',
                'supported', 'android_version', 'sdk_version', 'app_version',
                'command_uuid', 'command_type', 'source', 'result_code',
                'occurred_at', 'reason', 'sim_state', 'management_mode', 'compliance_status',
            ]),
        ]);

        $normalizedType = strtoupper($eventType);
        if (in_array($normalizedType, ['APP_REMOVED', 'ENROLLMENT_REMOVED', 'MANAGEMENT_REMOVED', 'MANAGEMENT_LOST'], true)) {
            $newStatus = in_array($normalizedType, ['APP_REMOVED', 'ENROLLMENT_REMOVED'], true) ? 'UNENROLLED' : 'AT_RISK';
            $previousStatus = $device->management_status ?? $device->enrollment_status;
            $device->forceFill([
                'management_status' => $newStatus,
                'last_seen_at' => now(),
            ])->saveQuietly();

            \App\Models\DeviceNotification::create([
                'company_id' => $device->company_id,
                'customer_id' => $device->customer_id,
                'device_id' => $device->id,
                'notification_type' => $newStatus === 'UNENROLLED' ? \App\Models\DeviceNotification::TYPE_DEVICE_UNENROLLED : \App\Models\DeviceNotification::TYPE_RE_ENROLLMENT_REQUIRED,
                'recipient_type' => 'customer',
                'title' => 'Device Management Status Alert',
                'message' => "Management event {$eventType} detected. Device status updated to {$newStatus}. Re-enrollment may be required.",
                'channel' => 'system',
                'delivery_status' => 'sent',
                'deduplication_key' => 'event-notif-'.$device->id.'-'.$eventType.'-'.now()->timestamp.'-'.\Illuminate\Support\Str::random(6),
                'metadata' => ['event_type' => $eventType, 'previous_status' => $previousStatus, 'new_status' => $newStatus],
            ]);

            app(AuditService::class)->record(
                'device.state_changed',
                $device,
                ['management_status' => $previousStatus],
                ['management_status' => $newStatus, 'event' => $eventType],
                "Device {$device->device_code} status transitioned to {$newStatus} due to {$eventType}"
            );
        }

        return $event;
    }
}
