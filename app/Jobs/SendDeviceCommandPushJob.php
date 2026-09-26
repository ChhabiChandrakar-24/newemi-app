<?php

namespace App\Jobs;

use App\Contracts\PushProviderInterface;
use App\Models\Company;
use App\Models\DeviceCommand;
use App\Models\DevicePushAttempt;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SendDeviceCommandPushJob implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $commandId, public readonly ?int $companyId = null) {}

    public function handle(PushProviderInterface $provider): void
    {
        $unscopedCommand = DeviceCommand::withoutGlobalScopes()->find($this->commandId);
        $company = Company::find($this->companyId ?? $unscopedCommand?->company_id);
        if (! $company) {
            return;
        }
        app(TenantContext::class)->set($company);
        $command = DeviceCommand::with('device')->find($this->commandId);
        if (! $command || ! in_array($command->status, DeviceCommand::ACTIVE_STATUSES, true) || $command->expires_at?->isPast()) {
            return;
        }
        $pushToken = $command->device->pushTokens()->where('provider', 'fcm')->where('is_active', true)->latest('registered_at')->first();
        if (! $pushToken) {
            return;
        }
        $attempt = DevicePushAttempt::create(['company_id' => $company->id, 'device_id' => $command->device_id, 'device_command_id' => $command->id, 'provider' => 'fcm', 'status' => 'pending', 'attempted_at' => now()]);
        $result = $provider->send($pushToken->token(), ['type' => 'device_command_available', 'command_reference' => $command->command_uuid, 'event_version' => '1'], true);
        $attempt->update(['status' => $result['status'], 'provider_message_id' => $result['message_id'], 'succeeded_at' => $result['status'] === 'sent' ? now() : null, 'failed_at' => $result['status'] !== 'sent' ? now() : null, 'failure_code' => $result['failure_code'], 'failure_message' => $result['failure_message']]);
        if ($result['status'] === 'sent') {
            $pushToken->update(['last_used_at' => now(), 'last_success_at' => now(), 'failure_count' => 0]);

            return;
        }
        $pushToken->increment('failure_count');
        $pushToken->update(['last_used_at' => now(), 'last_failure_at' => now()]);
        if ($result['status'] === 'invalid_token') {
            $pushToken->update(['is_active' => false, 'invalidated_at' => now()]);

            return;
        }
        if ($this->attempts() < $this->tries) {
            throw new RuntimeException('Transient FCM delivery failure.');
        }
    }
}
