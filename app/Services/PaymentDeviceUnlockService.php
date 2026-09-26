<?php

namespace App\Services;

use App\Models\EmiAccount;
use App\Models\User;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class PaymentDeviceUnlockService
{
    public function __construct(private readonly DeviceLockPolicyService $policies, private readonly DeviceCommandService $commands) {}

    public function queueIfCleared(EmiAccount $account, User $actor): int
    {
        $account->refresh();
        if (Money::toPaise($account->overdue_amount) !== 0) {
            return 0;
        }
        $count = 0;
        foreach ($account->devices()->where('enrollment_status', 'enrolled')->whereIn('control_status', ['warning', 'partial_lock', 'full_lock'])->get() as $device) {
            // Cancel pending lock commands
            \App\Models\DeviceCommand::where('device_id', $device->id)
                ->whereIn('command_type', ['full_lock', 'lock', 'partial_lock'])
                ->whereIn('status', \App\Models\DeviceCommand::ACTIVE_STATUSES)
                ->update(['status' => 'cancelled', 'remarks' => 'Cancelled due to payment clearance']);

            if (\Illuminate\Support\Facades\Schema::hasColumn('devices', 'device_lock_status')) {
                $device->updateQuietly(['device_lock_status' => 'UNLOCKED']);
            }

            $policy = $this->policies->resolve($device);
            if ($policy !== null && ! $policy->unlock_on_payment_clearance) {
                continue;
            }
            try {
                $result = $this->commands->queue($device, 'unlock', ['remarks' => 'Automatic unlock after verified payment clearance', 'force' => true], $actor, 'payment');
                $count += $result['created'] ? 1 : 0;
            } catch (ValidationException) {
                // Fail safe: financial verification succeeds; unsupported device control remains unchanged.
            }
        }

        return $count;
    }
}
