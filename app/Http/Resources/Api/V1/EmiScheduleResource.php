<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmiScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'installment_number' => $this->installment_number,
            'due_date' => $this->due_date?->toDateString(),
            'opening_balance' => $this->opening_balance,
            'principal_due' => $this->principal_due,
            'interest_due' => $this->interest_due,
            'installment_amount' => $this->installment_amount,
            'paid_amount' => $this->paid_amount,
            'outstanding_amount' => $this->outstanding_amount,
            'overdue_amount' => $this->overdue_amount,
            'paid_at' => $this->paid_at?->toISOString(),
            'grace_until' => $this->grace_until?->toDateString(),
            'status' => $this->status,
        ];
    }
}
