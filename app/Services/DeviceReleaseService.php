<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceRelease;
use App\Models\EmiAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeviceReleaseService
{
    public function __construct(
        private readonly DeviceService $devices,
        private readonly DeviceNotificationService $notifications,
        private readonly AuditService $audit,
    ) {}

    /**
     * Records a release event, performs the physical release through DeviceService
     * and notifies the customer/device. Used both by the manual release flow and
     * automatically after an EMI is fully paid.
     */
    public function release(
        Device $device,
        User $actor,
        string $reason = 'emi_completed',
        ?string $notes = null,
        ?array $metadata = null,
    ): DeviceRelease
    {
        return DB::transaction(function () use ($device, $actor, $reason, $notes, $metadata): DeviceRelease {
            $device = Device::query()->with(['company', 'emiAccount'])->lockForUpdate()->findOrFail($device->getKey());
            $company = $device->company ?? Company::find($device->company_id);

            $record = DeviceRelease::create([
                'company_id' => $device->company_id,
                'device_id' => $device->id,
                'customer_id' => $device->customer_id,
                'emi_account_id' => $device->emi_account_id,
                'released_by' => $actor->id,
                'release_reason' => $reason,
                'notes' => $notes,
                'metadata' => $metadata,
                'release_timestamp' => now(),
            ]);

            $this->devices->release($device, $actor);

            if ($company) {
                $this->notifications->notify($company, [
                    'device_id' => $device->id,
                    'customer_id' => $device->customer_id,
                    'emi_account_id' => $device->emi_account_id,
                    'notification_type' => 'device_released',
                    'recipient_type' => 'customer',
                    'title' => 'Device released from EMI enforcement',
                    'message' => 'Your EMI is complete or ended. Remote management and monitoring of your device have been disabled.',
                    'channel' => 'system',
                    'deduplication_key' => 'device_released-'.$device->id.'-'.$reason,
                ]);
            }

            $this->audit->record('device.release_recorded', $device, $actor, [
                'release_id' => $record->id, 'reason' => $reason,
            ]);

            return $record;
        });
    }

    public function releaseAccountDevices(EmiAccount $account, User $actor, string $reason = 'emi_completed'): int
    {
        $devices = $account->devices()->where('enrollment_status', '!=', 'released')->get();
        foreach ($devices as $device) {
            $this->release($device, $actor, $reason, 'Released automatically after EMI completion.');
        }

        return $devices->count();
    }
}