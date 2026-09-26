<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_code' => $this->payment_code,
            'receipt_number' => $this->receipt_number,
            'emi_account' => $this->whenLoaded('emiAccount', fn () => [
                'id' => $this->emiAccount->id,
                'emi_account_code' => $this->emiAccount->emi_account_code,
                'invoice_number' => $this->emiAccount->invoice_number,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'customer_code' => $this->customer->customer_code,
                'full_name' => $this->customer->full_name,
                'mobile_number' => $this->customer->mobile_number,
            ]),
            'payment_date' => $this->payment_date?->toDateString(),
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'transaction_reference' => $this->transaction_reference,
            'external_reference' => $this->external_reference,
            'payment_type' => $this->payment_type,
            'status' => $this->status,
            'notes' => $this->notes,
            'reversal_reason' => $this->reversal_reason,
            'collected_by' => $this->collected_by,
            'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toISOString(),
            'allocations' => PaymentAllocationResource::collection($this->whenLoaded('allocations')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
