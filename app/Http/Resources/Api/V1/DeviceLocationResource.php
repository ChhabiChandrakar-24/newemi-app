<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'device_id' => $this->device_id, 'latitude' => (float) $this->latitude, 'longitude' => (float) $this->longitude, 'accuracy_meters' => $this->accuracy_meters ? (float) $this->accuracy_meters : null, 'captured_at' => $this->captured_at?->toISOString(), 'received_at' => $this->received_at?->toISOString(), 'source' => $this->source, 'tracking_mode' => $this->tracking_mode];
    }
}
