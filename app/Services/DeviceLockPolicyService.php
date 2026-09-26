<?php

namespace App\Services;

use App\Models\Device;
use App\Models\EmiAccount;
use App\Models\LockPolicy;
use App\Support\Money;
use Carbon\CarbonImmutable;

class DeviceLockPolicyService
{
    public function resolve(Device $device): ?LockPolicy
    {
        return $device->lockPolicy()->where('is_active', true)->first()
            ?? LockPolicy::query()->where('is_default', true)->where('is_active', true)->first();
    }

    /** @return array{state:string,overdue_days:int,reason:string} */
    public function evaluate(Device $device, EmiAccount $account, LockPolicy $policy, ?CarbonImmutable $asOf = null): array
    {
        if (! $policy->is_active || ! $account->auto_lock_enabled || ! in_array($account->status, ['active', 'overdue'], true)) {
            return ['state' => 'no_action', 'overdue_days' => 0, 'reason' => 'policy_or_account_ineligible'];
        }
        if (Money::toPaise($account->overdue_amount) === 0) {
            $state = $policy->unlock_on_payment_clearance && in_array($device->control_status, ['warning', 'partial_lock', 'full_lock'], true) ? 'active' : 'no_action';

            return ['state' => $state, 'overdue_days' => 0, 'reason' => $state === 'active' ? 'payment_clearance' : 'not_overdue'];
        }

        $oldest = $account->schedules()->where('overdue_amount', '>', 0)->oldest('due_date')->first();
        if (! $oldest) {
            return ['state' => 'no_action', 'overdue_days' => 0, 'reason' => 'no_overdue_schedule'];
        }
        $grace = $policy->grace_period_override ?? $account->grace_period_days;
        $effectiveDue = CarbonImmutable::parse($oldest->due_date)->addDays($grace);
        $days = max(0, $effectiveDue->diffInDays(($asOf ?? CarbonImmutable::today()), false));
        $state = match (true) {
            $policy->full_lock_after_overdue_days !== null && $days >= $policy->full_lock_after_overdue_days => 'full_lock',
            $policy->partial_lock_after_overdue_days !== null && $days >= $policy->partial_lock_after_overdue_days => 'partial_lock',
            $days >= $policy->warning_after_overdue_days => 'warning',
            default => 'no_action',
        };

        return ['state' => $state, 'overdue_days' => $days, 'reason' => 'threshold_evaluation'];
    }
}
