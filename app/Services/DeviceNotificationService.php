<?php

namespace App\Services;

use App\Jobs\SendDeviceNotificationPushJob;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceNotificationService
{
    /**
     * Creates a device notification with a unique deduplication key and, when a
     * push channel with an active FCM token is available, dispatches it for delivery.
     */
    public function notify(Company $company, array $spec): DeviceNotification
    {
        return DB::transaction(function () use ($company, $spec): DeviceNotification {
            $deviceId = $spec['device_id'] ?? null;
            $deduplicationKey = $spec['deduplication_key'] ?? ('default-'.Str::ulid());

            $existing = DeviceNotification::where('deduplication_key', $deduplicationKey)->first();
            if ($existing) {
                // Idempotent re-delivery: refresh the title/message but never duplicate the row.
                $existing->update([
                    'delivery_status' => $existing->delivery_status === DeviceNotification::SENT ? $existing->delivery_status : 'queued',
                    'title' => $spec['title'] ?? $existing->title,
                    'message' => $spec['message'] ?? $existing->message,
                ]);
                $this->dispatchIfPushable($company, $existing);

                return $existing;
            }

            $device = $deviceId ? Device::query()->find($deviceId) : null;
            $channel = $spec['channel'] ?? DeviceNotification::CHANNEL_PUSH;
            $pushable = $device && $channel === DeviceNotification::CHANNEL_PUSH
                && $device->pushTokens()->where('provider', 'fcm')->where('is_active', true)->exists();

            $notification = DeviceNotification::create([
                'company_id' => $company->id,
                'device_id' => $spec['device_id'] ?? null,
                'customer_id' => $spec['customer_id'] ?? $device?->customer_id,
                'emi_account_id' => $spec['emi_account_id'] ?? $device?->emi_account_id,
                'notification_type' => $spec['notification_type'] ?? 'system',
                'recipient_type' => $spec['recipient_type'] ?? 'device',
                'title' => $spec['title'] ?? 'Device notification',
                'message' => $spec['message'] ?? null,
                'delivery_status' => $pushable ? DeviceNotification::QUEUED : DeviceNotification::SKIPPED,
                'channel' => $channel,
                'deduplication_key' => $deduplicationKey,
                'metadata' => $spec['metadata'] ?? null,
            ]);

            if ($pushable) {
                SendDeviceNotificationPushJob::dispatch($notification->id, (int) $company->id)->onQueue('device-push')->afterCommit();
            }

            return $notification;
        });
    }

    public function markSent(DeviceNotification $notification, bool $success, ?string $failureCode = null): void
    {
        $notification->update([
            'delivery_status' => $success ? DeviceNotification::SENT : DeviceNotification::FAILED,
            'delivered_at' => $success ? now() : $notification->delivered_at,
            'metadata' => $success ? null : ['failure_code' => $failureCode],
        ]);
    }

    private function dispatchIfPushable(Company $company, DeviceNotification $notification): void
    {
        if ($notification->delivery_status === DeviceNotification::SENT
            || $notification->channel !== DeviceNotification::CHANNEL_PUSH
            || ! $notification->device_id) {
            return;
        }
        $pushable = Device::query()->where('id', $notification->device_id)
            ->whereHas('pushTokens', fn ($query) => $query
                ->where('provider', 'fcm')->where('is_active', true))
            ->exists();
        if ($pushable) {
            SendDeviceNotificationPushJob::dispatch($notification->id, (int) $company->id)->onQueue('device-push')->afterCommit();
        }
    }
}