<?php

namespace App\Services;

use App\Models\Device;
use Illuminate\Validation\ValidationException;

class DeviceCommandCapabilityService
{
    public function ensureSupported(Device $device, string $type): void
    {
        if (($device->released_at || $device->enrollment_status === 'released') && ! in_array($type, ['release', 'policy_sync', 'release_prepare'], true)) {
            $this->fail('device_released', 'Released devices cannot receive lock or configuration commands.');
        }
        if (! in_array($device->enrollment_status, ['enrolled', 'released'], true)) {
            $this->fail('device_not_enrolled', 'The device is not enrolled.');
        }
        if (! $device->customer?->consent_given) {
            $this->fail('consent_required', 'Current customer consent is required.');
        }
        if (in_array($type, ['full_lock', 'lock', 'partial_lock'], true) && ($device->management_status === 'RELEASED' || in_array($device->emiAccount?->status, ['completed', 'paid', 'closed'], true) || $device->emiAccount?->emi_status === 'PAID')) {
            $this->fail('emi_completed', 'Device is released from EMI management and cannot be locked.');
        }

        $requiresManagedDevice = in_array($type, ['partial_lock', 'full_lock', 'lock', 'unlock', 'release', 'release_prepare'], true);
        if ($requiresManagedDevice && ! in_array($device->management_mode, ['device_owner', 'fully_managed', 'device_admin', 'unknown'], true)) {
            $this->fail('unsupported_management_mode', 'The management mode does not support remote device control.');
        }

        $capabilities = $device->capabilities ?? [];
        $hasAnyCapability = empty($capabilities) || count(array_filter($capabilities)) > 0;
        $supported = match ($type) {
            'show_warning' => true,
            'partial_lock' => (bool) ($capabilities['partial_lock'] ?? $capabilities['can_enter_lock_task'] ?? $hasAnyCapability),
            'full_lock', 'lock' => (bool) ($capabilities['full_lock'] ?? $capabilities['can_enter_lock_task'] ?? $hasAnyCapability),
            'unlock', 'release' => true,
            'policy_sync', 'refresh_status', 'release_prepare' => true,
            default => true,
        };
        if (! $supported) {
            $this->fail('unsupported_capability', "The device does not report support for {$type}.");
        }
    }

    private function fail(string $code, string $message): never
    {
        throw ValidationException::withMessages(['command' => [[
            'code' => $code,
            'message' => $message,
        ]]]);
    }
}
