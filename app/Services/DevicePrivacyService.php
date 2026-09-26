<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Support\Facades\DB;

class DevicePrivacyService
{
    public function __construct(private readonly AuditService $audit) {}

    public function eraseAfterCompletedEmi(int $deviceId): void
    {
        DB::transaction(function () use ($deviceId): void {
            $device = Device::withoutGlobalScopes()->lockForUpdate()->with('emiAccount')->find($deviceId);
            if (! $device || ! in_array($device->emiAccount?->status, ['completed', 'closed'], true)) {
                return;
            }

            // Revoke/remove only temporary or active management credentials
            $device->credentials()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $device->pushTokens()->where('is_active', true)->update(['is_active' => false, 'invalidated_at' => now()]);
            $device->enrollments()->where('status', 'pending')->update(['status' => 'revoked', 'revoked_at' => now()]);

            // Ensure permanent device identity and history remain intact:
            // DO NOT delete IMEI, serial_number, customer_id, emi_account_id, events, enrollments, commands, or audit logs
            $device->update([
                'management_status' => 'RELEASED',
                'enrollment_status' => 'released',
                'device_lock_status' => 'UNLOCKED',
                'control_status' => 'normal',
                'desired_control_status' => 'normal',
                'released_at' => $device->released_at ?? now(),
                'privacy_erased_at' => $device->privacy_erased_at ?? now(),
                'location_tracking_enabled' => false,
                'location_tracking_mode' => 'disabled',
                'location_consent_withdrawn_at' => now(),
            ]);

            $this->audit->record('device.credentials_revoked_after_emi', $device, null, [
                'device_code' => $device->device_code,
                'imei1' => $device->imei1,
                'serial_number' => $device->serial_number,
                'emi_account_status' => $device->emiAccount?->status,
                'management_status' => 'RELEASED',
                'retained_permanent_identity' => true,
            ], 'Active credentials revoked and device released following completed installment plan; permanent identity retained.');
        });
    }
}
