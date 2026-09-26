<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_code' => $this->customer_code,
            'full_name' => $this->full_name,
            'mobile_number' => $this->mobile_number,
            'alternate_mobile_number' => $this->alternate_mobile_number,
            'email' => $this->email,
            'address' => [
                'line_1' => $this->address_line_1,
                'line_2' => $this->address_line_2,
                'city' => $this->city,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
                'country' => $this->country,
            ],
            'identity_type' => $this->identity_type,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'status' => $this->status,
            'notes' => $this->notes,
            'consent' => [
                'given' => $this->consent_given,
                'given_at' => $this->consent_given_at?->toISOString(),
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
