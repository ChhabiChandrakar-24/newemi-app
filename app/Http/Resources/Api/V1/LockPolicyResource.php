<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LockPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'is_default' => $this->is_default, 'is_active' => $this->is_active,
            'warning_after_overdue_days' => $this->warning_after_overdue_days, 'partial_lock_after_overdue_days' => $this->partial_lock_after_overdue_days,
            'full_lock_after_overdue_days' => $this->full_lock_after_overdue_days, 'unlock_on_payment_clearance' => $this->unlock_on_payment_clearance,
            'grace_period_override' => $this->grace_period_override, 'offline_behavior' => $this->offline_behavior,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString()];
    }
}
