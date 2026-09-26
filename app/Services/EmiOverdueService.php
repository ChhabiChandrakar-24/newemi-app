<?php

namespace App\Services;

use App\Models\EmiAccount;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EmiOverdueService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return array{schedule_statuses_changed: int, overdue_installments: int} */
    public function recalculate(EmiAccount $account, ?CarbonImmutable $asOf = null): array
    {
        $now = $asOf ?? CarbonImmutable::now();

        return DB::transaction(function () use ($account, $now): array {
            $account = EmiAccount::withoutGlobalScopes()->with('company')->lockForUpdate()->findOrFail($account->getKey());
            $action = function () use ($account, $now): array {
                $changed = 0;
                $overdueCount = 0;
                $overduePaise = 0;
                $outstandingPaise = 0;
                $nextDueDate = null;

                foreach ($account->schedules()->orderBy('installment_number')->get() as $schedule) {
                    if ($schedule->status === 'cancelled') {
                        continue;
                    }

                $installment = Money::toPaise($schedule->installment_amount);
                $paid = $schedule->status === 'paid'
                    ? $installment
                    : min(Money::toPaise($schedule->paid_amount), $installment);
                $adjustment = Money::toPaise((string) $schedule->paymentAllocations()
                    ->where('allocation_type', 'adjustment')
                    ->whereHas('payment', fn ($payment) => $payment->where('status', 'verified'))
                    ->sum('allocated_amount'));
                $outstanding = max(0, $installment - $paid - $adjustment);
                $newStatus = $schedule->status;
                $scheduleOverdue = 0;

                if ($outstanding === 0) {
                    $newStatus = $adjustment > 0 ? 'waived' : 'paid';
                } elseif ($now->lt($schedule->due_date)) {
                    $newStatus = $paid > 0 ? 'partially_paid' : 'pending';
                } elseif ($now->lte($schedule->grace_until ?? $schedule->due_date)) {
                    $newStatus = $paid > 0 ? 'partially_paid' : 'due';
                } else {
                    $newStatus = 'overdue';
                    $scheduleOverdue = $outstanding;
                    $overdueCount++;
                    $overduePaise += $outstanding;
                }

                if ($newStatus !== $schedule->status || Money::toPaise($schedule->outstanding_amount) !== $outstanding || Money::toPaise($schedule->overdue_amount) !== $scheduleOverdue) {
                    $changed++;
                    $schedule->update([
                        'status' => $newStatus,
                        'outstanding_amount' => Money::fromPaise($outstanding),
                        'overdue_amount' => Money::fromPaise($scheduleOverdue),
                    ]);
                }

                $outstandingPaise += $outstanding;
                if ($outstanding > 0 && ($nextDueDate === null || $schedule->due_date->lt($nextDueDate))) {
                    $nextDueDate = $schedule->due_date;
                }
            }

            $totalPaidPaise = Money::toPaise((string) $account->payments()->where('status', 'verified')->sum('amount'));
            $lastPaymentDate = $account->payments()->where('status', 'verified')->max('payment_date');

            $accountStatus = $account->status;
            $oldAccountStatus = $accountStatus;
            if (in_array($accountStatus, ['active', 'overdue'], true)) {
                $accountStatus = $outstandingPaise === 0 ? 'completed' : ($overdueCount > 0 ? 'overdue' : 'active');
            }

            $account->update([
                'total_paid' => Money::fromPaise($totalPaidPaise),
                'outstanding_amount' => Money::fromPaise($outstandingPaise),
                'overdue_amount' => Money::fromPaise($overduePaise),
                'overdue_installments' => $overdueCount,
                'next_due_date' => $nextDueDate?->toDateString(),
                'last_payment_date' => $lastPaymentDate,
                'status' => $accountStatus,
            ]);

            if ($oldAccountStatus !== $accountStatus) {
                $this->audit->record(
                    'emi_account.status_changed',
                    $account,
                    ['status' => $oldAccountStatus],
                    ['status' => $accountStatus, 'source' => 'overdue_recalculation'],
                );
            }

            if ($overdueCount >= 3 && in_array($accountStatus, ['active', 'overdue'], true)) {
                $account->update(['emi_status' => 'LOCKED']);
                $accountId = $account->id;
                $account->devices()
                    ->withoutGlobalScopes()
                    ->get()
                    ->filter(fn ($d) => in_array($d->enrollment_status, ['enrolled', 'managed']) && $d->management_status !== 'RELEASED')
                    ->each(function ($device) use ($accountId, $overdueCount): void {
                        $device->updateQuietly(['device_lock_status' => 'LOCK_PENDING']);
                        app(DeviceCommandService::class)->queue($device, 'full_lock', [
                            'message' => 'Lock for your device and go to shop',
                            'support_message' => "EMI default: {$overdueCount} installments overdue. Please contact the shop to clear your account.",
                            'remarks' => "System-generated lock for {$overdueCount} overdue installments.",
                            'idempotency_key' => "overdue-lock:{$accountId}:{$overdueCount}",
                        ], null, 'policy');
                    });
            }

            return ['schedule_statuses_changed' => $changed, 'overdue_installments' => $overdueCount];
            };

            return $account->company ? app(\App\Support\TenantContext::class)->run($account->company, $action) : $action();
        });
    }
}
