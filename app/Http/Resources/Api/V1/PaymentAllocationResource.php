<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'emi_schedule_id' => $this->emi_schedule_id,
            'installment_number' => $this->whenLoaded('emiSchedule', fn () => $this->emiSchedule->installment_number),
            'allocated_amount' => $this->allocated_amount,
            'allocation_type' => $this->allocation_type,
        ];
    }
}
