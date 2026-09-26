<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Device;
use App\Models\EmiAccount;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeviceService
{
    private const ENROLLMENT_TRANSITIONS = [
        'pending' => ['failed'],
        'enrolled' => ['suspended'],
        'suspended' => [],
        'failed' => [],
        'released' => [],
    ];

    public function __construct(private readonly AuditService $audit) {}

    public function register(array $data, User $actor): Device
    {
        return DB::transaction(function () use ($data, $actor): Device {
            $company = app(TenantContext::class)->company();
            if (! $actor->is_platform_admin) {
                if ($company?->subscription_status === 'expired' || ($company?->expires_at && $company->expires_at->isPast())) {
                    throw ValidationException::withMessages(['company' => ['Your company subscription has expired or was revoked. Please recharge your plan to register new devices.']]);
                }
                if ($company?->max_devices && Device::query()->count() >= $company->max_devices) {
                    throw ValidationException::withMessages(['company' => ["Device limit ({$company->max_devices}) reached for your active plan. Please recharge or upgrade your plan."]]);
                }
            }
            Customer::query()->findOrFail($data['customer_id']);
            $this->validateAccount($data['emi_account_id'] ?? null, (int) $data['customer_id']);
            $device = Device::query()->create([
                ...$data,
                'device_code' => 'TMP-'.Str::ulid(),
                'internal_device_uuid' => (string) Str::uuid(),
                'management_mode' => 'unmanaged',
                'enrollment_status' => 'pending',
                'control_status' => 'active',
                'connectivity_status' => 'unknown',
                'compliance_status' => 'unknown',
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $device->updateQuietly(['device_code' => sprintf('DEV-%06d', $device->getKey())]);
            $device->refresh();
            $this->audit->record('device.registered', $device, null, $this->snapshot($device));

            return $device->load(['customer', 'emiAccount']);
        });
    }

    public function update(Device $device, array $data, User $actor): Device
    {
        return DB::transaction(function () use ($device, $data, $actor): Device {
            $old = $this->snapshot($device);
            if (array_key_exists('emi_account_id', $data)) {
                $this->validateAccount($data['emi_account_id'], $device->customer_id);
            }
            $device->update([...$data, 'updated_by' => $actor->getKey()]);
            $this->audit->record('device.updated', $device, $old, $this->snapshot($device));

            return $device->load(['customer', 'emiAccount']);
        });
    }

    public function changeStatus(Device $device, array $data, User $actor): Device
    {
        return DB::transaction(function () use ($device, $data, $actor): Device {
            $old = ['control_status' => $device->control_status, 'enrollment_status' => $device->enrollment_status];
            if (isset($data['enrollment_status'])) {
                if (! in_array($data['enrollment_status'], self::ENROLLMENT_TRANSITIONS[$device->enrollment_status] ?? [], true)) {
                    throw ValidationException::withMessages(['enrollment_status' => ['Invalid enrollment status transition.']]);
                }
                if ($data['enrollment_status'] === 'suspended') {
                    $device->credentials()->whereNull('revoked_at')->update(['revoked_at' => now()]);
                    $device->pushTokens()->where('is_active', true)->update(['is_active' => false, 'invalidated_at' => now()]);
                    $this->audit->record('device.suspended', $device, $old, $data);
                }
            }
            $device->update([...$data, 'updated_by' => $actor->getKey()]);
            $this->audit->record('device.status_changed', $device, $old, $data);

            return $device;
        });
    }

    public function release(Device $device, User $actor): Device
    {
        return DB::transaction(function () use ($device, $actor): Device {
            $device = Device::query()->lockForUpdate()->findOrFail($device->getKey());
            if ($device->emiAccount && ! in_array($device->emiAccount->status, ['completed', 'closed'], true)) {
                throw ValidationException::withMessages(['device' => ['Linked EMI account must be completed or closed before release.']]);
            }
            if ($device->enrollment_status === 'released') {
                throw ValidationException::withMessages(['device' => ['Device is already released.']]);
            }
            $device->enrollments()->where('status', 'pending')->update(['status' => 'revoked', 'revoked_at' => now()]);
            $device->credentials()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $device->update([
                'location_permission_state' => 'revoked',
                'enrollment_status' => 'released',
                'management_status' => 'RELEASED',
                'device_lock_status' => 'UNLOCKED',
                'control_status' => 'normal',
                'released_at' => now(),
                'updated_by' => $actor->getKey(),
            ]);

            $device->locations()->delete();

            $device->releases()->create([
                'company_id' => $device->company_id,
                'customer_id' => $device->customer_id,
                'emi_account_id' => $device->emi_account_id,
                'released_by' => $actor->getKey(),
                'release_reason' => 'emi_completed',
                'notes' => 'Device released from EMI management.',
                'release_timestamp' => now(),
                'metadata' => [
                    'restrictions_lifted' => true,
                    'monitoring_stopped' => true,
                ],
            ]);

            $device->notifications()->create([
                'company_id' => $device->company_id,
                'customer_id' => $device->customer_id,
                'emi_account_id' => $device->emi_account_id,
                'notification_type' => 'DEVICE_RELEASED',
                'recipient_type' => 'customer',
                'channel' => 'system',
                'title' => 'Device Released from EMI Management',
                'message' => 'Congratulations! Your EMI contract is fully completed and device management restrictions have been removed.',
                'delivery_status' => 'sent',
                'deduplication_key' => 'device_released:' . $device->id . ':' . now()->timestamp,
                'delivered_at' => now(),
                'created_at' => now(),
            ]);

            $this->audit->record('device.released', $device, null, [
                'enrollment_status' => 'released',
                'management_status' => 'RELEASED',
                'control_status' => 'released',
            ]);

            return $device;
        });
    }

    public function delete(Device $device, User $actor): void
    {
        if ($device->enrollment_status !== 'pending' || $device->credentials()->exists()) {
            throw ValidationException::withMessages(['device' => ['Only never-enrolled pending devices can be deleted.']]);
        }
        DB::transaction(function () use ($device, $actor): void {
            $device->update(['updated_by' => $actor->getKey()]);
            $device->delete();
            $this->audit->record('device.deleted', $device, $this->snapshot($device), null);
        });
    }

    private function validateAccount(?int $accountId, int $customerId): void
    {
        if ($accountId === null) {
            return;
        }
        $account = EmiAccount::query()->findOrFail($accountId);
        if ($account->customer_id !== $customerId) {
            throw ValidationException::withMessages(['emi_account_id' => ['EMI account does not belong to the customer.']]);
        }
        if (in_array($account->status, ['cancelled', 'closed'], true)) {
            throw ValidationException::withMessages(['emi_account_id' => ['Cancelled or closed EMI accounts cannot accept a new device.']]);
        }
    }

    private function snapshot(Device $device): array
    {
        return $device->only(['device_code', 'customer_id', 'emi_account_id', 'display_name', 'brand', 'model', 'invoice_number', 'enrollment_status', 'control_status']);
    }
}
