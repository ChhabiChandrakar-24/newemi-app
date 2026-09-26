<?php

namespace App\Jobs;

use App\Contracts\PushProviderInterface;
use App\Models\Company;
use App\Models\DeviceNotification;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SendDeviceNotificationPushJob implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $notificationId, public readonly ?int $companyId = null) {}

    public function handle(PushProviderInterface $provider, DeviceNotificationService $notifications): void
    {
        $unscoped = DeviceNotification::withoutGlobalScopes()->find($this->notificationId);
        if (! $unscoped || $unscoped->delivery_status === DeviceNotification::SENT) {
            return;
        }
        $company = Company::find($this->companyId ?? $unscoped->company_id);
        if (! $company) {
            return;
        }
        app(TenantContext::class)->set($company);
        $notification = DeviceNotification::with('device')->find($this->notificationId);
        if (! $notification) {
            return;
        }
        $pushToken = $notification->device?->pushTokens()->where('provider', 'fcm')->where('is_active', true)->latest('registered_at')->first();
        if (! $pushToken) {
            $notifications->markSent($notification, false, 'no_push_token');

            return;
        }
        $result = $provider->send($pushToken->token(), [
            'type' => 'device_notification',
            'notification_type' => $notification->notification_type,
            'title' => $notification->title,
            'message' => $notification->message ?? '',
            'notification_id' => (string) $notification->id,
            'event_version' => '1',
        ], false);
        if ($result['status'] === 'sent') {
            $pushToken->update(['last_used_at' => now(), 'last_success_at' => now(), 'failure_count' => 0]);
            $notifications->markSent($notification, true);

            return;
        }
        $pushToken->increment('failure_count');
        $pushToken->update(['last_used_at' => now(), 'last_failure_at' => now()]);
        if ($result['status'] === 'invalid_token') {
            $pushToken->update(['is_active' => false, 'invalidated_at' => now()]);
            $notifications->markSent($notification, false, 'unregistered');

            return;
        }
        if ($this->attempts() < $this->tries) {
            throw new RuntimeException('Transient FCM delivery failure.');
        }
        $notifications->markSent($notification, false, $result['failure_code']);
    }
}