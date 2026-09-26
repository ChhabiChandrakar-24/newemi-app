<?php

namespace App\Services;

use App\Models\EmiAccount;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentReversalService
{
    public function __construct(
        private readonly EmiOverdueService $overdue,
        private readonly AuditService $audit,
    ) {}

    public function reverse(Payment $payment, User $actor, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $reason): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());
            if ($payment->status !== 'verified') {
                throw ValidationException::withMessages(['payment' => ['Only a verified payment can be reversed.']]);
            }

            $account = EmiAccount::query()->lockForUpdate()->findOrFail($payment->emi_account_id);
            $allocations = $payment->allocations()->with('emiSchedule')->lockForUpdate()->get();
            $payment->update([
                'status' => 'reversed',
                'reversal_reason' => $reason,
                'updated_by' => $actor->getKey(),
            ]);

            foreach ($allocations->where('allocation_type', 'installment') as $allocation) {
                $schedule = $allocation->emiSchedule;
                $newPaid = max(0, Money::toPaise($schedule->paid_amount) - Money::toPaise($allocation->allocated_amount));
                $schedule->update([
                    'paid_amount' => Money::fromPaise($newPaid),
                    'status' => $newPaid > 0 ? 'partially_paid' : 'pending',
                    'paid_at' => null,
                ]);
            }

            foreach ($allocations->where('allocation_type', 'adjustment') as $allocation) {
                $allocation->emiSchedule->update(['status' => 'pending']);
            }

            if ($account->status === 'completed') {
                $account->update(['status' => 'active']);
            }
            $this->overdue->recalculate($account);
            $this->audit->record(
                'payment.reversed',
                $payment,
                ['status' => 'verified'],
                ['status' => 'reversed', 'reason' => $reason],
            );

            return $payment->load(['emiAccount', 'customer', 'allocations.emiSchedule']);
        });
    }
}
