<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmiAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'emi_account_code' => $this->emi_account_code,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'customer_code' => $this->customer->customer_code,
                'full_name' => $this->customer->full_name,
                'mobile_number' => $this->customer->mobile_number,
            ]),
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'product_description' => $this->product_description,
            'financed_amount' => $this->financed_amount,
            'down_payment' => $this->down_payment,
            'principal_amount' => $this->principal_amount,
            'total_installments' => $this->total_installments,
            'installment_amount' => $this->installment_amount,
            'emi_frequency' => $this->emi_frequency,
            'emi_frequency_days' => $this->emi_frequency_days,
            'emi_start_date' => $this->emi_start_date?->toISOString(),
            'emi_end_date' => $this->emi_end_date,
            'due_day' => $this->due_day,
            'grace_period_days' => $this->grace_period_days,
            'interest_amount' => $this->interest_amount,
            'processing_fee' => $this->processing_fee,
            'other_charges' => $this->other_charges,
            'total_payable' => $this->total_payable,
            'total_paid' => $this->total_paid,
            'outstanding_amount' => $this->outstanding_amount,
            'overdue_amount' => $this->overdue_amount,
            'overdue_installments' => $this->overdue_installments,
            'next_due_date' => $this->next_due_date?->toDateString(),
            'last_payment_date' => $this->last_payment_date?->toDateString(),
            'status' => $this->status,
            'emi_status' => $this->emi_status,
            'auto_lock_enabled' => $this->auto_lock_enabled,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
