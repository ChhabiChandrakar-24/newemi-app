<?php

namespace App\Services;

use App\Models\EmiAccount;
use App\Models\Payment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class PaymentAllocationService
{
    public function allocatePayment(Payment $payment, EmiAccount $account): void
    {
        $remaining = Money::toPaise($payment->amount);
        $schedules = $account->schedules()
            ->where('outstanding_amount', '>', 0)
            ->orderByRaw("CASE status WHEN 'overdue' THEN 0 WHEN 'due' THEN 1 WHEN 'partially_paid' THEN 2 ELSE 3 END")
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->lockForUpdate()
            ->get();

        foreach ($schedules as $schedule) {
            if ($remaining === 0) {
                break;
            }

            $outstanding = Money::toPaise($schedule->outstanding_amount);
            $allocation = min($remaining, $outstanding);
            $newPaid = Money::toPaise($schedule->paid_amount) + $allocation;
            $newOutstanding = $outstanding - $allocation;
            $schedule->update([
                'paid_amount' => Money::fromPaise($newPaid),
                'outstanding_amount' => Money::fromPaise($newOutstanding),
                'overdue_amount' => Money::fromPaise(min(Money::toPaise($schedule->overdue_amount), $newOutstanding)),
                'status' => $newOutstanding === 0 ? 'paid' : 'partially_paid',
                'paid_at' => $newOutstanding === 0 ? CarbonImmutable::parse($payment->payment_date)->endOfDay() : null,
            ]);
            $payment->allocations()->create([
                'emi_schedule_id' => $schedule->getKey(),
                'allocated_amount' => Money::fromPaise($allocation),
                'allocation_type' => 'installment',
            ]);
            $remaining -= $allocation;
        }

        if ($remaining !== 0) {
            throw ValidationException::withMessages(['amount' => ['Payment could not be fully allocated.']]);
        }
    }

    public function allocateAdjustment(Payment $payment, EmiAccount $account, int $adjustmentPaise): void
    {
        $remaining = $adjustmentPaise;
        $schedules = $account->schedules()
            ->where('outstanding_amount', '>', 0)
            ->orderByRaw("CASE status WHEN 'overdue' THEN 0 WHEN 'due' THEN 1 WHEN 'partially_paid' THEN 2 ELSE 3 END")
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->lockForUpdate()
            ->get();

        foreach ($schedules as $schedule) {
            if ($remaining === 0) {
                break;
            }

            $outstanding = Money::toPaise($schedule->outstanding_amount);
            $allocation = min($remaining, $outstanding);
            $newOutstanding = $outstanding - $allocation;
            $schedule->update([
                'outstanding_amount' => Money::fromPaise($newOutstanding),
                'overdue_amount' => Money::fromPaise(min(Money::toPaise($schedule->overdue_amount), $newOutstanding)),
                'status' => $newOutstanding === 0 ? 'waived' : $schedule->status,
            ]);
            $payment->allocations()->create([
                'emi_schedule_id' => $schedule->getKey(),
                'allocated_amount' => Money::fromPaise($allocation),
                'allocation_type' => 'adjustment',
            ]);
            $remaining -= $allocation;
        }

        if ($remaining !== 0) {
            throw ValidationException::withMessages(['discount_amount' => ['Discount could not be fully allocated.']]);
        }
    }
}
