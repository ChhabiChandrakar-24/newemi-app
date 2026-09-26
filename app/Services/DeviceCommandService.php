<?php

namespace App\Services;

use App\Contracts\DeviceCommandNotifierInterface;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceManagementSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeviceCommandService
{
    private const DESIRED = [
        'show_warning' => 'warning', 'partial_lock' => 'partial_lock',
        'full_lock' => 'full_lock', 'lock' => 'full_lock',
        'unlock' => 'unlocked', 'release' => 'unlocked', 'release_prepare' => 'unlocked',
    ];

    public function __construct(
        private readonly DeviceCommandCapabilityService $capabilities,
        private readonly DeviceEventService $events,
        private readonly AuditService $audit,
        private readonly DeviceCommandNotifierInterface $notifier,
        private readonly DeviceManagementSettingsService $managementSettings,
        private readonly DevicePrivacyService $privacy,
    ) {}

    /** @return array{command: DeviceCommand, created: bool} */
    public function queue(Device $device, string $type, array $data = [], ?User $actor = null, string $source = 'manual'): array
    {
        if (! in_array($type, DeviceCommand::TYPES, true)) {
            throw ValidationException::withMessages(['command_type' => ['Unsupported command type.']]);
        }
        $this->capabilities->ensureSupported($device->loadMissing(['customer', 'company', 'emiAccount']), $type);

        return DB::transaction(function () use ($device, $type, $data, $actor, $source): array {
            $device = Device::query()->lockForUpdate()->with(['customer', 'company', 'emiAccount'])->findOrFail($device->getKey());
            $this->capabilities->ensureSupported($device, $type);
            $idempotencyKey = $data['idempotency_key'] ?? null;
            if ($idempotencyKey) {
                $existing = $device->commands()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    if ($existing->command_type !== $type) {
                        throw ValidationException::withMessages(['idempotency_key' => ['This key was used for a different command.']]);
                    }

                    return ['command' => $existing, 'created' => false];
                }
            }
            $existing = $device->commands()->where('command_type', $type)
                ->whereIn('status', DeviceCommand::ACTIVE_STATUSES)->latest('id')->first();
            if ($existing && ! ($data['force'] ?? false)) {
                return ['command' => $existing, 'created' => false];
            }

            $desired = self::DESIRED[$type] ?? null;
            $settings = $this->managementSettings->forCompany($device->company_id);
            $ttl = min((int) ($data['expires_in_minutes'] ?? $settings->command_ttl_minutes), 1440);
            $command = $device->commands()->create([
                'company_id' => $device->company_id,
                'command_uuid' => (string) Str::uuid(), 'command_type' => $type,
                'requested_control_status' => $desired, 'payload' => $this->safePayload($device, $type, $data),
                'status' => 'queued', 'priority' => $data['priority'] ?? $this->priority($type),
                'source' => $source, 'requested_by' => $actor?->getKey(), 'requested_at' => now(),
                'available_at' => now(), 'expires_at' => now()->addMinutes(max(1, $ttl)),
                'max_retries' => $settings->command_max_retries, 'remarks' => $data['remarks'] ?? null,
                'idempotency_key' => $idempotencyKey, 'correlation_id' => $data['correlation_id'] ?? Str::uuid(),
            ]);
            if ($desired) {
                $device->updateQuietly(['desired_control_status' => $desired]);
            }
            $this->events->record($device, 'command_queued', 'info', ['command_uuid' => $command->command_uuid, 'command_type' => $type, 'source' => $source]);
            $this->audit->record("device.command.{$type}.queued", $command, ['control_status' => $device->control_status], [
                'device_id' => $device->id, 'emi_account_id' => $device->emi_account_id,
                'desired_control_status' => $desired, 'command_uuid' => $command->command_uuid, 'source' => $source,
            ], $data['remarks'] ?? $data['reason'] ?? null);
            $this->notifier->notify($command);

            return ['command' => $command, 'created' => true];
        });
    }

    public function pull(Device $device): ?DeviceCommand
    {
        return DB::transaction(function () use ($device): ?DeviceCommand {
            $this->expireForDevice($device);
            $this->recoverTimedOutDispatches($device);
            $command = DeviceCommand::query()->where('device_id', $device->id)->where('status', 'queued')
                ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderByDesc('priority')->orderBy('requested_at')->lockForUpdate()->first();
            if (! $command) {
                return null;
            }
            $command->update(['status' => 'dispatched', 'sent_at' => now(), 'retry_count' => $command->retry_count + 1]);

            return $command->fresh();
        });
    }

    public function markReceived(Device $device, DeviceCommand $command): DeviceCommand
    {
        return $this->transitionForDevice($device, $command, ['dispatched'], 'received', ['received_at' => now()], 'command_received');
    }

    public function acknowledge(Device $device, DeviceCommand $command): DeviceCommand
    {
        return $this->transitionForDevice($device, $command, ['received'], 'acknowledged', ['acknowledged_at' => now()]);
    }

    public function result(Device $device, DeviceCommand $command, array $data): DeviceCommand
    {
        return DB::transaction(function () use ($device, $command, $data): DeviceCommand {
            $command = DeviceCommand::query()->lockForUpdate()->findOrFail($command->id);
            if ($command->device_id !== $device->id) {
                abort(404);
            }
            if (! in_array($command->status, ['received', 'acknowledged'], true)) {
                throw ValidationException::withMessages(['status' => ['Command is not ready for a result.']]);
            }
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            if (! $data['applied']) {
                $oldStatus = $command->status;
                $command->update(['status' => 'failed', 'failed_at' => now(), 'failure_code' => $data['result_code'] ?? 'application_failed',
                    'failure_message' => $data['result_message'] ?? null, 'result_payload' => $this->safeResult($data)]);
                $device->updateQuietly(['desired_control_status' => $device->control_status]);
                $this->events->record($device, 'command_failed', 'high', ['command_uuid' => $command->command_uuid, 'command_type' => $command->command_type, 'result_code' => $command->failure_code]);
                $this->audit->record('device.command.failed', $command, ['status' => $oldStatus], [
                    'status' => 'failed', 'device_id' => $device->id, 'command_uuid' => $command->command_uuid,
                    'failure_code' => $command->failure_code, 'source' => $command->source,
                ]);

                return $command->fresh();
            }
            $expected = $command->requested_control_status;
            $resulting = $data['resulting_control_status'] ?? $expected;
            if ($expected && $resulting !== $expected) {
                throw ValidationException::withMessages(['resulting_control_status' => ['Resulting state does not match the requested state.']]);
            }
            $command->update(['status' => 'applied', 'applied_at' => now(), 'result_message' => $data['result_message'] ?? null, 'result_payload' => $this->safeResult($data)]);
            if ($expected) {
                $deviceUpdates = [
                    'control_status' => $expected,
                    'desired_control_status' => $expected,
                ];
                if (\Illuminate\Support\Facades\Schema::hasColumn('devices', 'device_lock_status')) {
                    $deviceUpdates['device_lock_status'] = match ($command->command_type) {
                        'full_lock', 'lock' => 'LOCKED',
                        'partial_lock' => 'LOCK_PENDING',
                        'unlock', 'release', 'release_prepare' => 'UNLOCKED',
                        default => $device->device_lock_status ?? null,
                    };
                }
                $device->updateQuietly($deviceUpdates);
            }
            $this->events->record($device, 'command_applied', 'info', ['command_uuid' => $command->command_uuid, 'command_type' => $command->command_type]);
            $event = match ($command->command_type) {
                'show_warning' => 'warning_applied', 'partial_lock' => 'partial_lock_applied', 'full_lock' => 'full_lock_applied', 'unlock' => 'unlock_applied', default => null
            };
            if ($event) {
                $this->events->record($device, $event, 'info', ['command_uuid' => $command->command_uuid]);
            }
            if ($command->command_type === 'release_prepare') {
                $deviceId = $device->id;
                DB::afterCommit(fn () => $this->privacy->eraseAfterCompletedEmi($deviceId));
            }

            return $command->fresh();
        });
    }

    public function cancel(DeviceCommand $command, User $actor, ?string $remarks): DeviceCommand
    {
        return DB::transaction(function () use ($command, $remarks): DeviceCommand {
            $command = DeviceCommand::query()->lockForUpdate()->with('device')->findOrFail($command->id);
            if (! in_array($command->status, ['queued', 'dispatched'], true)) {
                throw ValidationException::withMessages(['command' => ['Only queued or dispatched commands may be cancelled.']]);
            }
            $old = $command->status;
            $command->update(['status' => 'cancelled', 'remarks' => $remarks ?? $command->remarks]);
            $this->refreshDesiredState($command->device);
            $this->audit->record('device.command.cancelled', $command, ['status' => $old], ['status' => 'cancelled', 'command_uuid' => $command->command_uuid], $remarks);

            return $command;
        });
    }

    public function expireAll(): int
    {
        $count = 0;
        DeviceCommand::query()->whereIn('status', DeviceCommand::ACTIVE_STATUSES)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->chunkById(100, function ($commands) use (&$count): void {
                foreach ($commands as $command) {
                    $command->update(['status' => 'expired']);
                    $this->refreshDesiredState($command->device);
                    $count++;
                }
            });

        return $count;
    }

    private function expireForDevice(Device $device): void
    {
        $device->commands()->whereIn('status', DeviceCommand::ACTIVE_STATUSES)->whereNotNull('expires_at')->where('expires_at', '<=', now())->update(['status' => 'expired']);
        $this->refreshDesiredState($device);
    }

    private function recoverTimedOutDispatches(Device $device): void
    {
        $cutoff = now()->subMinutes(config('devices.command_ack_timeout_minutes'));
        $stale = $device->commands()->where('status', 'dispatched')->where('sent_at', '<=', $cutoff)->lockForUpdate()->get();
        foreach ($stale as $command) {
            if ($command->retry_count >= $command->max_retries) {
                $command->update(['status' => 'failed', 'failed_at' => now(), 'failure_code' => 'delivery_retry_exhausted', 'failure_message' => 'The device did not confirm command receipt.']);
                $this->events->record($device, 'command_failed', 'high', ['command_uuid' => $command->command_uuid, 'command_type' => $command->command_type, 'result_code' => 'delivery_retry_exhausted']);
                $this->audit->record('device.command.failed', $command, ['status' => 'dispatched'], [
                    'status' => 'failed', 'device_id' => $device->id, 'command_uuid' => $command->command_uuid,
                    'failure_code' => 'delivery_retry_exhausted', 'source' => $command->source,
                ]);
            } else {
                $delay = DeviceManagementSetting::withoutGlobalScopes()->where('company_id', $device->company_id)->value('command_retry_delay_seconds') ?? 30;
                $command->update(['status' => 'queued', 'next_attempt_at' => now()->addSeconds($delay), 'sent_at' => null]);
            }
        }
        if ($stale->isNotEmpty()) {
            $this->refreshDesiredState($device);
        }
    }

    private function transitionForDevice(Device $device, DeviceCommand $command, array $from, string $to, array $fields, ?string $event = null): DeviceCommand
    {
        return DB::transaction(function () use ($device, $command, $from, $to, $fields, $event): DeviceCommand {
            $command = DeviceCommand::query()->lockForUpdate()->findOrFail($command->id);
            if ($command->device_id !== $device->id) {
                abort(404);
            }
            if (! in_array($command->status, $from, true)) {
                throw ValidationException::withMessages(['status' => ["Invalid {$command->status} to {$to} transition."]]);
            }
            $command->update(['status' => $to, ...$fields]);
            if ($event) {
                $this->events->record($device, $event, 'info', ['command_uuid' => $command->command_uuid, 'command_type' => $command->command_type]);
            }

            return $command->fresh();
        });
    }

    private function safePayload(Device $device, string $type, array $data): array
    {
        $device->loadMissing(['company', 'customer', 'emiAccount']);
        $company = $device->company;
        $account = $device->emiAccount;
        $customer = $device->customer;
        $settings = $this->managementSettings->forCompany($device->company_id);

        $shopAddressParts = array_filter([
            $company?->address_line_1,
            $company?->address_line_2,
            $company?->city,
            $company?->state,
            $company?->postal_code,
            $company?->country,
        ]);
        $shopAddress = !empty($shopAddressParts) 
            ? implode(', ', $shopAddressParts) 
            : 'Vill-bhainshmundi, Kurud, Dhamtari, CG / New Delhi, India';

        $supportPhone = $company?->support_phone ?: ($company?->phone ?: '+919981887943');
        $supportEmail = $company?->support_email ?: ($company?->email ?: 'chhabichandrakar3@gmail.com');

        $dueAmount = '0.00';
        $overdueAmount = '0.00';
        $outstandingAmount = '0.00';
        $installmentAmount = '0.00';
        $overdueCount = 0;

        if ($account) {
            $outstanding = (float) ($account->outstanding_amount ?? 0);
            $overdue = (float) ($account->overdue_amount ?? 0);
            $installment = (float) ($account->installment_amount ?? 0);
            $overdueCount = (int) ($account->overdue_installments ?? 0);

            $outstandingAmount = number_format($outstanding, 2, '.', '');
            $overdueAmount = number_format($overdue, 2, '.', '');
            $installmentAmount = number_format($installment, 2, '.', '');

            if ($account->status === 'completed' || $outstanding <= 0) {
                $dueAmount = '0.00';
                $overdueAmount = '0.00';
                $outstandingAmount = '0.00';
                $overdueCount = 0;
            } elseif ($overdue > 0) {
                $dueAmount = $overdueAmount;
                if ($overdueCount <= 0) {
                    $overdueCount = 1;
                }
            } elseif ($outstanding > 0) {
                $dueAmount = $installment > 0 ? $installmentAmount : $outstandingAmount;
            }
        }

        $customerMobile = $customer?->mobile_number ?: ($customer?->alternate_mobile_number ?: '+919981887943');

        $payload = array_filter([
            'company_name' => $company?->name ?? 'My EMI Finance',
            'shop_name' => $company?->name ?? 'My EMI Finance Shop',
            'shop_id' => $company?->id,
            'shop_code' => $company?->company_code ?? 'CMP-000001',
            'support_phone' => $supportPhone,
            'support_email' => $supportEmail,
            'support_contact' => $supportEmail ?: $supportPhone,
            'shop_address' => $shopAddress,
            'customer_display_name' => $data['customer_display_name'] ?? ($customer?->full_name ?? 'Customer'),
            'customer_name' => $customer?->full_name ?? 'ajay',
            'customer_mobile' => $customerMobile,
            'customer_code' => $customer?->customer_code ?? 'CUS-000002',
            'emi_account_code' => $account?->emi_account_code,
            'emi_status' => ($account?->status === 'completed' || (float) ($account?->outstanding_amount ?? 0) <= 0) ? 'PAID' : ($account?->emi_status ?? $account?->status ?? 'ACTIVE'),
            'due_amount' => $dueAmount,
            'outstanding_amount' => $outstandingAmount,
            'overdue_amount' => $overdueAmount,
            'installment_amount' => $installmentAmount,
            'total_installments' => $account?->total_installments ?? 1,
            'paid_installments' => (string) ($account?->total_paid ?? '0.00'),
            'next_due_date' => $account?->next_due_date ? (string) $account->next_due_date : null,
            'overdue_installments' => $overdueCount,
            'upi_id' => $settings->shop_upi_id ?: '9981887943@upi',
            'lock_reason' => $data['lock_reason'] ?? $data['message'] ?? $data['reason'] ?? 'Device locked due to pending EMI installment. Please pay online or contact shop to unlock.',
            'lock_title' => $data['lock_title'] ?? ($type === 'show_warning' ? 'PAYMENT REMINDER' : 'DEVICE LOCKED'),
            'message' => $data['message'] ?? ($type === 'show_warning' ? 'Please review your EMI payment status.' : 'Device locked due to pending EMI installment.'),
            'support_message' => $data['support_message'] ?? 'Please pay online or visit shop to unlock immediately.',
            'issued_at' => now()->toIso8601String(),
            'command_type' => $type,
        ], fn ($value) => $value !== null);

        if (in_array($type, ['policy_sync', 'partial_lock', 'full_lock', 'lock'], true)) {
            $isFullLock = in_array($type, ['full_lock', 'lock'], true);
            $defaultRestrictions = [
                'restrict_factory_reset' => $isFullLock || (bool) ($settings->factory_reset_restriction_enabled ?? true),
                'restrict_safe_boot' => $isFullLock || (bool) ($settings->safe_boot_restriction_enabled ?? true),
                'restrict_user_changes' => (bool) ($settings->user_changes_restriction_enabled ?? false),
                'restrict_unknown_sources' => (bool) ($settings->unknown_sources_restriction_enabled ?? false),
            ];
            $customRestrictions = isset($data['restrictions']) ? Arr::only($data['restrictions'], [
                'restrict_factory_reset', 'restrict_safe_boot', 'restrict_user_changes', 'restrict_unknown_sources',
            ]) : [];
            $restrictions = array_merge($defaultRestrictions, $customRestrictions);
            if ($isFullLock) {
                $restrictions['restrict_factory_reset'] = true;
                $restrictions['restrict_safe_boot'] = true;
            }

            $payload += array_filter([
                'policy_version' => $data['policy_version'] ?? null,
                'allowed_packages' => $data['allowed_packages'] ?? null,
                'restrictions' => $restrictions,
            ], fn ($value) => $value !== null);
        }

        return $payload;
    }

    private function safeResult(array $data): array
    {
        return Arr::only($data, ['resulting_control_status', 'result_code', 'result_message', 'capability_snapshot', 'policy_version']);
    }

    private function priority(string $type): int
    {
        return match ($type) {
            'unlock', 'release', 'release_prepare' => 100, 'full_lock', 'lock' => 80, 'partial_lock' => 70, 'show_warning' => 60, default => 50
        };
    }

    private function refreshDesiredState(Device $device): void
    {
        $pending = $device->commands()->whereIn('status', DeviceCommand::ACTIVE_STATUSES)->whereNotNull('requested_control_status')->orderByDesc('priority')->latest('id')->first();
        $device->updateQuietly(['desired_control_status' => $pending?->requested_control_status ?? $device->control_status]);
    }
}
