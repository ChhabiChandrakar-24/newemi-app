<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceEnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'enrollment_code' => $this->enrollment_code, 'device_id' => $this->device_id,
            'status' => $this->status, 'expires_at' => $this->expires_at?->toISOString(),
            'used_at' => $this->used_at?->toISOString(), 'revoked_at' => $this->revoked_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
