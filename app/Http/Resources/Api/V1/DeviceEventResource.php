<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'device' => $this->whenLoaded('device', fn () => $this->device ? [
                'id' => $this->device->id,
                'device_code' => $this->device->device_code,
                'display_name' => $this->device->display_name,
                'brand' => $this->device->brand,
                'model' => $this->device->model,
                'imei1' => $this->device->imei1,
                'customer' => $this->device->customer ? [
                    'id' => $this->device->customer->id,
                    'customer_code' => $this->device->customer->customer_code,
                    'full_name' => $this->device->customer->full_name,
                    'mobile_number' => $this->device->customer->mobile_number,
                ] : null,
                'emi_account' => $this->device->emiAccount ? [
                    'id' => $this->device->emiAccount->id,
                    'emi_account_code' => $this->device->emiAccount->emi_account_code,
                    'status' => $this->device->emiAccount->status,
                ] : null,
            ] : null),
            'event_type' => $this->event_type,
            'severity' => $this->severity,
            'event_time' => $this->event_time?->toISOString(),
            'metadata' => $this->payload,
            'acknowledged_at' => $this->acknowledged_at?->toISOString(),
            'acknowledged_by' => $this->acknowledged_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
