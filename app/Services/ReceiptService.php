<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Validation\ValidationException;

class ReceiptService
{
    public function data(Payment $payment): array
    {
        if (! in_array($payment->status, ['verified', 'reversed'], true) || ! $payment->receipt_number) {
            throw ValidationException::withMessages(['payment' => ['A receipt is available only after payment verification.']]);
        }

        $payment->load(['customer', 'emiAccount', 'collector', 'allocations.emiSchedule']);

        return [
            'receipt_number' => $payment->receipt_number,
            'payment_code' => $payment->payment_code,
            'payment_status' => $payment->status,
            'reversal_reason' => $payment->reversal_reason,
            'customer' => [
                'id' => $payment->customer->id,
                'customer_code' => $payment->customer->customer_code,
                'full_name' => $payment->customer->full_name,
                'mobile_number' => $payment->customer->mobile_number,
            ],
            'emi_account' => [
                'id' => $payment->emiAccount->id,
                'emi_account_code' => $payment->emiAccount->emi_account_code,
                'invoice_number' => $payment->emiAccount->invoice_number,
            ],
            'amount' => $payment->amount,
            'payment_method' => $payment->payment_method,
            'payment_date' => $payment->payment_date->toDateString(),
            'transaction_reference' => $payment->transaction_reference,
            'collector' => $payment->collector ? ['id' => $payment->collector->id, 'name' => $payment->collector->name] : null,
            'allocations' => $payment->allocations->map(fn ($allocation) => [
                'installment_number' => $allocation->emiSchedule->installment_number,
                'allocated_amount' => $allocation->allocated_amount,
                'allocation_type' => $allocation->allocation_type,
            ])->values(),
            'remaining_account_balance' => $payment->emiAccount->outstanding_amount,
        ];
    }
}
