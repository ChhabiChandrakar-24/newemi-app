<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceConsentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device' => $this->whenLoaded('device', fn () => $this->device ? [
                'id' => $this->device->id,
                'device_code' => $this->device->device_code,
                'display_name' => $this->device->display_name,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'customer_code' => $this->customer->customer_code,
                'full_name' => $this->customer->full_name,
            ] : null),
            'emi_account_id' => $this->emi_account_id,
            'consent_status' => $this->consent_status,
            'consent_timestamp' => $this->consent_timestamp?->toISOString(),
            'withdrawn_at' => $this->withdrawn_at?->toISOString(),
            'terms_version' => $this->terms_version,
            'privacy_version' => $this->privacy_version,
            'consent_device_id' => $this->consent_device_id,
            'accepted_terms' => (bool) $this->accepted_terms,
            'accepted_conditions' => (bool) $this->accepted_conditions,
            'accepted_privacy' => (bool) $this->accepted_privacy,
            'enrollment_timestamp' => $this->enrollment_timestamp?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}