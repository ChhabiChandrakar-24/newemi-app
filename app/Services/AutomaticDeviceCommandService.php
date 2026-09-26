<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\LockPolicy;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AutomaticDeviceCommandService
{
    public function __construct(
        private readonly DeviceLockPolicyService $policies,
        private readonly DeviceCommandService $commands,
        private readonly DeviceManagementSettingsService $managementSettings,
    ) {}

    /** @return array{outcome:string,state:string,command_id?:int,step?:int,max_warnings?:int,action?:string} */
    public function evaluate(Device $device, ?User $actor = null, bool $forceInterval = false): array
    {
        $device->load(['emiAccount.schedules', 'customer', 'lockPolicy', 'company']);
        if ($device->policy_automation_paused || ! $device->emiAccount) {
            return ['outcome' => 'skipped', 'state' => 'no_action', 'reason' => 'paused_or_no_account'];
        }

        $account = $device->emiAccount;
        $settings = $this->managementSettings->forCompany($device->company_id);

        $isOverdue = Money::toPaise($account->overdue_amount) > 0 
            || $account->status === 'overdue' 
            || $account->schedules()->where('overdue_amount', '>', 0)->exists();

        $isFullyPaidOrZeroOverdue = ($account->status === 'completed' || $account->emi_status === 'PAID')
            || (! $isOverdue && (float) ($account->outstanding_amount ?? 0) <= 0);

        // 1. AUTO-UNLOCK: If paid / zero overdue and auto_unlock_after_payment is enabled
        if ($isFullyPaidOrZeroOverdue) {
            $lockedStates = ['warning', 'partial_lock', 'full_lock', 'locked', 'restricted'];
            $needsUnlock = in_array($device->control_status, $lockedStates, true)
                || in_array($device->desired_control_status, $lockedStates, true);

            if ($needsUnlock && ($settings->auto_unlock_after_payment ?? true)) {
                try {
                    $result = $this->commands->queue($device, 'unlock', [
                        'force' => true,
                        'reason' => 'Payment clearance: automatic unlock executed',
                        'remarks' => 'Automatic unlock executed: EMI payment cleared or account up to date.',
                    ], $actor, 'payment');

                    return [
                        'outcome' => $result['created'] ? 'queued' : 'duplicate',
                        'state' => 'unlocked',
                        'action' => 'auto_unlock',
                        'command_id' => $result['command']->id,
                    ];
                } catch (ValidationException $e) {
                    return ['outcome' => 'unsupported', 'state' => 'unlocked', 'error' => $e->getMessage()];
                }
            }

            return ['outcome' => 'unchanged', 'state' => 'active', 'reason' => 'not_overdue'];
        }

        // 2. LOCK POLICY OR PROGRESSIVE 3-WARNING: When account is overdue
        $policy = $device->lockPolicy ?? LockPolicy::query()->where('is_default', true)->where('is_active', true)->first();
        if ($policy) {
            $evaluation = $this->policies->evaluate($device, $account, $policy);
            $targetState = $evaluation['state'];
            if (in_array($targetState, ['warning', 'partial_lock', 'full_lock'], true)) {
                $commandType = match ($targetState) {
                    'warning' => 'show_warning',
                    'partial_lock' => 'partial_lock',
                    'full_lock' => 'full_lock',
                };
                $result = $this->commands->queue($device, $commandType, [
                    'reason' => "Lock policy threshold reached ({$targetState})",
                    'remarks' => "Automatic enforcement: {$targetState} threshold reached.",
                ], $actor, 'policy');

                return [
                    'outcome' => $result['created'] ? 'queued' : 'duplicate',
                    'state' => $targetState,
                    'command_id' => $result['command']->id,
                ];
            }
        }

        if ($settings->progressive_warning_enabled ?? true) {
            $maxWarnings = (int) ($settings->progressive_warning_count ?? 3);
            $intervalUnit = $settings->warning_escalation_interval_unit ?? 'minutes';
            $intervalValue = (int) ($settings->warning_escalation_interval_value ?? 1);
            $autoLockAction = $settings->auto_lock_action ?? 'full_lock';

            $oldestSchedule = $account->schedules()->where('overdue_amount', '>', 0)->oldest('due_date')->first();
            $overdueSince = $oldestSchedule?->due_date 
                ? CarbonImmutable::parse($oldestSchedule->due_date) 
                : now()->subDays(1);

            // Count warning commands queued for this overdue cycle
            $warningCount = $device->commands()
                ->where('command_type', 'show_warning')
                ->where('created_at', '>=', $overdueSince)
                ->count();

            $lastWarning = $device->commands()
                ->where('command_type', 'show_warning')
                ->latest('created_at')
                ->first();

            $intervalElapsed = false;
            if ($forceInterval || ! $lastWarning) {
                $intervalElapsed = true;
            } else {
                $diff = match ($intervalUnit) {
                    'minutes' => $lastWarning->created_at->diffInMinutes(now()),
                    'hours' => $lastWarning->created_at->diffInHours(now()),
                    'days' => $lastWarning->created_at->diffInDays(now()),
                    default => $lastWarning->created_at->diffInMinutes(now()),
                };
                $intervalElapsed = $diff >= $intervalValue;
            }

            // Step A: Warnings 1, 2, 3
            if ($warningCount < $maxWarnings) {
                if (! $intervalElapsed) {
                    return [
                        'outcome' => 'waiting_interval',
                        'state' => 'warning',
                        'step' => $warningCount,
                        'max_warnings' => $maxWarnings,
                        'interval' => "{$intervalValue} {$intervalUnit}",
                        'last_warning_at' => $lastWarning?->created_at?->toISOString(),
                    ];
                }

                $step = $warningCount + 1;
                $installmentAmount = $account->installment_amount ?? $account->overdue_amount ?? 0;
                $urgencyPrefix = $step === $maxWarnings ? '🚨 FINAL WARNING' : "⚠️ Warning {$step} of {$maxWarnings}";

                try {
                    $result = $this->commands->queue($device, 'show_warning', [
                        'force' => true,
                        'lock_title' => "{$urgencyPrefix}: EMI Overdue",
                        'message' => "Installment of ₹{$installmentAmount} is overdue. Pay immediately to prevent automatic device locking. (Warning {$step}/{$maxWarnings})",
                        'support_message' => "Warning {$step} of {$maxWarnings}. Device will be locked automatically if payment is not received within the interval ({$intervalValue} {$intervalUnit}).",
                        'reason' => "progressive_warning_step_{$step}",
                        'remarks' => "Progressive warning {$step}/{$maxWarnings} queued automatically (interval: {$intervalValue} {$intervalUnit}).",
                    ], $actor, 'policy');

                    return [
                        'outcome' => $result['created'] ? 'queued' : 'duplicate',
                        'state' => 'warning',
                        'step' => $step,
                        'max_warnings' => $maxWarnings,
                        'command_id' => $result['command']->id,
                    ];
                } catch (ValidationException $e) {
                    return ['outcome' => 'unsupported', 'state' => 'warning', 'error' => $e->getMessage()];
                }
            }

            // Step B: Exceeded max warnings -> Enforce auto lock!
            if (! $intervalElapsed && ! $forceInterval && $device->control_status !== $autoLockAction && $device->desired_control_status !== $autoLockAction) {
                return [
                    'outcome' => 'waiting_lock_interval',
                    'state' => 'warning_completed',
                    'step' => $warningCount,
                    'max_warnings' => $maxWarnings,
                    'interval' => "{$intervalValue} {$intervalUnit}",
                ];
            }

            $isAlreadyLocked = in_array($device->control_status, ['full_lock', 'partial_lock', 'locked', 'restricted'], true)
                && in_array($device->desired_control_status, ['full_lock', 'partial_lock', 'locked', 'restricted'], true);

            if (! $isAlreadyLocked) {
                try {
                    $result = $this->commands->queue($device, $autoLockAction, [
                        'force' => true,
                        'lock_title' => 'DEVICE RESTRICTED: EMI OVERDUE',
                        'message' => "This device is locked because {$maxWarnings} warnings were issued without receiving EMI payment.",
                        'support_message' => "Auto-lock enforced after {$maxWarnings} warnings. Clear all dues online or contact shop to unlock.",
                        'reason' => "exceeded_{$maxWarnings}_progressive_warnings",
                        'remarks' => "Automatic {$autoLockAction} queued after exceeding {$maxWarnings} warnings without payment.",
                    ], $actor, 'policy');

                    return [
                        'outcome' => $result['created'] ? 'queued' : 'duplicate',
                        'state' => $autoLockAction,
                        'action' => 'auto_locked',
                        'command_id' => $result['command']->id,
                    ];
                } catch (ValidationException $e) {
                    return ['outcome' => 'unsupported', 'state' => $autoLockAction, 'error' => $e->getMessage()];
                }
            }

            return ['outcome' => 'unchanged', 'state' => $device->control_status];
        }

        // Fallback to traditional lock policy if progressive warning is disabled
        if (! ($policy = $this->policies->resolve($device))) {
            return ['outcome' => 'skipped', 'state' => 'no_action'];
        }
        $recommendation = $this->policies->evaluate($device, $device->emiAccount, $policy);
        $state = $recommendation['state'];
        if ($state === 'no_action' || $state === $device->control_status || $state === $device->desired_control_status) {
            return ['outcome' => 'unchanged', 'state' => $state];
        }
        $type = match ($state) {
            'warning' => 'show_warning', 'active' => 'unlock', default => $state
        };
        try {
            $result = $this->commands->queue($device, $type, [
                'reason' => $recommendation['reason'],
                'remarks' => "Policy {$policy->name}; overdue days {$recommendation['overdue_days']}",
            ], $actor, $state === 'active' ? 'payment' : 'policy');

            return ['outcome' => $result['created'] ? 'queued' : 'duplicate', 'state' => $state, 'command_id' => $result['command']->id];
        } catch (ValidationException) {
            return ['outcome' => 'unsupported', 'state' => $state];
        }
    }
}
