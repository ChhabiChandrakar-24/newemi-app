<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataRetentionPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'data_type' => $this->data_type,
            'retention_period_days' => $this->retention_period_days,
            'action' => $this->action,
            'reason' => $this->reason,
            'is_active' => (bool) $this->is_active,
            'last_applied_at' => $this->last_applied_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}