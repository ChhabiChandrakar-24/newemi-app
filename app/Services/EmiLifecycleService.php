<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Device;
use App\Models\EmiAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmiLifecycleService
{
    public const ACTIVE = 'ACTIVE';
    public const DUE = 'DUE';
    public const OVERDUE = 'OVERDUE';
    public const WARNING = 'WARNING';
    public const GRACE_PERIOD = 'GRACE_PERIOD';
    public const RESTRICTED = 'RESTRICTED';
    public const LOCKED = 'LOCKED';
    public const PAID = 'PAID';
    public const RELEASED = 'RELEASED';
    public const CLOSED = 'CLOSED';
    public const AT_RISK = 'AT_RISK';
    public const UNENROLLED = 'UNENROLLED';

    public const TITLES = [
        EmiLifecycleService::ACTIVE => 'Active',
        EmiLifecycleService::DUE => 'Due',
        EmiLifecycleService::OVERDUE => 'Overdue',
        EmiLifecycleService::WARNING => 'Warning',
        EmiLifecycleService::GRACE_PERIOD => 'Grace period',
        EmiLifecycleService::RESTRICTED => 'Restricted',
        EmiLifecycleService::LOCKED => 'Locked',
        EmiLifecycleService::PAID => 'Paid',
        EmiLifecycleService::RELEASED => 'Released',
        EmiLifecycleService::CLOSED => 'Closed',
        EmiLifecycleService::AT_RISK => 'At risk',
        EmiLifecycleService::UNENROLLED => 'Unenrolled',
    ];

    public function __construct(
        private readonly EmiOverdueService $overdue,
        private readonly DeviceNotificationService $notifications,
        private readonly DeviceReleaseService $releaseService,
        private readonly AuditService $audit,
    ) {}

    /**
     * Deterministic state-machine transition for a single EMI account.
     *
     * @param iterable<array{control_status: ?string, management_status: ?string, enrollment_status: ?string}> $deviceStates
     */
    public function compute(EmiAccount $account, iterable $deviceStates = []): string
    {
        if (in_array($account->status, ['cancelled', 'closed'], true)) {
            return EmiLifecycleService::CLOSED;
        }
        if ($account->status === 'completed' || $account->outstanding_amount <= 0) {
            return EmiLifecycleService::PAID;
        }

        $states = collect($deviceStates);
        $hasDevices = $states->isNotEmpty();
        $allReleased = $hasDevices;
        foreach ($states as $state) {
            $state = (array) $state;
            if (($state['management_status'] ?? null) !== 'RELEASED' && ($state['enrollment_status'] ?? null) !== 'released') {
                $allReleased = false;
                break;
            }
        }
        if ($allReleased) {
            return EmiLifecycleService::RELEASED;
        }
        $controlStatuses = $states->pluck('control_status')->all();
        if (in_array('full_locked', $controlStatuses, true)) {
            return EmiLifecycleService::LOCKED;
        }
        if (in_array('partial_locked', $controlStatuses, true)) {
            return EmiLifecycleService::RESTRICTED;
        }

        if ($account->overdue_amount > 0 && $account->overdue_installments > 0) {
            $graceDays = (int) ($account->grace_period_days ?? 0);
            $dueDate = $account->next_due_date ? \Carbon\Carbon::parse($account->next_due_date)->startOfDay() : null;
            $now = now()->startOfDay();
            $daysOverdue = $dueDate ? max(0, abs((int) $now->diffInDays($dueDate))) : 1;

            return $daysOverdue <= $graceDays ? EmiLifecycleService::GRACE_PERIOD : EmiLifecycleService::WARNING;
        }

        return EmiLifecycleService::ACTIVE;
    }

    /** Alias for recalculateForAccount to evaluate and synchronize account state. */
    public function syncAccount(EmiAccount $account, ?User $actor = null, array $options = []): array
    {
        return $this->recalculateForAccount($account, $actor, $options);
    }

    /** Recomputes and persists emi_status, notifying on any transition. */
    public function recalculateForAccount(EmiAccount $account, ?User $actor = null, array $options = []): array
    {
        return DB::transaction(function () use ($account, $actor, $options): array {
            $account = EmiAccount::withoutGlobalScopes()->lockForUpdate()->findOrFail($account->getKey());

            if (($options['recalculate_overdue'] ?? true) && ! in_array($account->status, ['completed', 'cancelled', 'closed'], true)) {
                $this->overdue->recalculate($account);
                $account->refresh();
            }

            $deviceStates = $account->devices()
                ->withoutGlobalScopes()
                ->whereNotNull('enrollment_status')
                ->select(['control_status', 'management_status', 'enrollment_status'])
                ->get()
                ->map(fn ($device) => [
                    'control_status' => $device->control_status,
                    'management_status' => $device->management_status,
                    'enrollment_status' => $device->enrollment_status,
                ]);
            $newStatus = $this->compute($account, $deviceStates);
            $previousStatus = $account->emi_status ?? null;

            if ($previousStatus && $previousStatus === $newStatus) {
                return ['account' => $account, 'previous_status' => $previousStatus, 'status' => $newStatus, 'changed' => false];
            }

            $account->update(['emi_status' => $newStatus]);
            $firstDeviceId = $account->devices()->withoutGlobalScopes()->whereNotNull('enrollment_status')->value('id');

            $this->notifications->notify(Company::findOrFail($account->company_id), [
                'emi_account_id' => $account->id,
                'customer_id' => $account->customer_id,
                'device_id' => $firstDeviceId,
                'notification_type' => 'emi_status',
                'recipient_type' => 'customer',
                'title' => 'EMI status updated to '.EmiLifecycleService::TITLES[$newStatus],
                'message' => $this->messageFor($newStatus),
                'channel' => 'system',
                'deduplication_key' => 'emi_status-'.$account->id.'-'.$newStatus,
            ]);

            $this->audit->record('emi.lifecycle_updated', $account, $actor, [
                'previous_status' => $previousStatus, 'status' => $newStatus,
            ]);

            return ['account' => $account->refresh(), 'previous_status' => $previousStatus, 'status' => $newStatus, 'changed' => true];
        });
    }

    /** @return array{processed: int, changed: int, by_status: array<string,int>} */
    public function recalculateAll(?Company $company = null): array
    {
        $query = EmiAccount::query();
        if ($company) {
            $query->where('company_id', $company->id);
        }
        $accounts = $query->whereIn('status', ['active', 'overdue'])->get();

        $processed = 0;
        $changed = 0;
        $byStatus = [];
        foreach ($accounts as $account) {
            $result = $this->recalculateForAccount($account, null, ['recalculate_overdue' => false]);
            $processed++;
            if ($result['changed']) {
                $changed++;
                $byStatus[$result['status']] = ($byStatus[$result['status']] ?? 0) + 1;
            }
        }

        return ['processed' => $processed, 'changed' => $changed, 'by_status' => $byStatus];
    }

    /**
     * Completes an EMI when the outstanding balance is fully settled: sets the
     * financial status, transitions the lifecycle to PAID, releases every
     * enrolled device from enforcement and notifies the customer.
     */
    public function complete(EmiAccount $account, User $actor): EmiAccount
    {
        return DB::transaction(function () use ($account, $actor): EmiAccount {
            $account = EmiAccount::query()->with('customer')->lockForUpdate()->findOrFail($account->getKey());
            if (in_array($account->status, ['completed', 'cancelled', 'closed'], true)) {
                throw ValidationException::withMessages(['emi_account' => ['Only active or overdue EMI accounts can be completed.']]);
            }
            if ($account->outstanding_amount > 0) {
                throw ValidationException::withMessages(['outstanding_amount' => ['EMI cannot be completed while an outstanding balance remains.']]);
            }

            $account->update([
                'status' => 'completed',
                'emi_status' => EmiLifecycleService::PAID,
                'overdue_amount' => 0,
                'overdue_installments' => 0,
                'auto_lock_enabled' => false,
            ]);

            // Cancel any pending lock commands
            \App\Models\DeviceCommand::whereIn('device_id', $account->devices()->pluck('id'))
                ->whereIn('command_type', ['full_lock', 'lock', 'partial_lock'])
                ->whereIn('status', \App\Models\DeviceCommand::ACTIVE_STATUSES)
                ->update(['status' => 'cancelled', 'remarks' => 'Cancelled due to complete payment']);

            $released = $this->releaseService->releaseAccountDevices($account, $actor, 'emi_completed');

            $this->notifications->notify(Company::findOrFail($account->company_id), [
                'emi_account_id' => $account->id,
                'customer_id' => $account->customer_id,
                'notification_type' => 'emi_completed',
                'recipient_type' => 'customer',
                'title' => 'EMI completed',
                'message' => 'Congratulations! Your EMI has been fully paid. Your device has been released from EMI enforcement.',
                'channel' => 'system',
                'deduplication_key' => 'emi_completed-'.$account->id,
            ]);

            $this->audit->record('emi.completed', $account, $actor, [
                'devices_released' => $released,
            ]);

            return $account->refresh()->load('customer');
        });
    }

    /** Computes the device-level management status from connectivity + consent signals. */
    public function computeManagementStatus(Device $device, int $atRiskAfterMinutes, int $unenrolledAfterMinutes): string
    {
        if ($device->enrollment_status === 'released') {
            return EmiLifecycleService::RELEASED;
        }
        if (in_array($device->control_status, ['full_locked', 'partial_locked'], true)) {
            return $device->control_status === 'full_locked' ? EmiLifecycleService::LOCKED : EmiLifecycleService::RESTRICTED;
        }
        if ($device->enrollment_status === 'unenrolled') {
            return EmiLifecycleService::UNENROLLED;
        }
        if ($device->last_heartbeat_at && $device->last_heartbeat_at->addMinutes($atRiskAfterMinutes)->isPast()) {
            return EmiLifecycleService::AT_RISK;
        }

        return EmiLifecycleService::ACTIVE;
    }

    private function messageFor(string $status): string
    {
        switch ($status) {
            case EmiLifecycleService::WARNING:
                return 'Your EMI is overdue. Please pay at the earliest to avoid restrictions on your device.';
            case EmiLifecycleService::GRACE_PERIOD:
                return 'Your EMI is overdue but within the grace period. Please clear the dues before the grace period ends.';
            case EmiLifecycleService::RESTRICTED:
                return 'Your EMI is significantly overdue. Some device features are temporarily restricted.';
            case EmiLifecycleService::LOCKED:
                return 'Your EMI is overdue. The device has been locked until the outstanding amount is cleared.';
            default:
                return 'Your EMI account status is now '.EmiLifecycleService::TITLES[$status] ?? $status.'.';
        }
    }
}