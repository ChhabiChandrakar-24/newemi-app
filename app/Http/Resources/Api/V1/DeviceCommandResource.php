<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceCommandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lastPush = $this->pushAttempts()->latest('attempted_at')->first();

        return [
            'id' => $this->id, 'command_uuid' => $this->command_uuid, 'device_id' => $this->device_id,
            'command_type' => $this->command_type, 'requested_control_status' => $this->requested_control_status,
            'payload' => $this->payload, 'status' => $this->status, 'priority' => $this->priority, 'source' => $this->source,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester ? ['id' => $this->requester->id, 'name' => $this->requester->name] : null),
            'requested_at' => $this->requested_at?->toISOString(), 'available_at' => $this->available_at?->toISOString(),
            'sent_at' => $this->sent_at?->toISOString(), 'received_at' => $this->received_at?->toISOString(),
            'acknowledged_at' => $this->acknowledged_at?->toISOString(), 'applied_at' => $this->applied_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(), 'expires_at' => $this->expires_at?->toISOString(),
            'retry_count' => $this->retry_count, 'max_retries' => $this->max_retries,
            'failure_code' => $this->failure_code, 'failure_message' => $this->failure_message,
            'result_message' => $this->result_message, 'remarks' => $this->remarks, 'correlation_id' => $this->correlation_id,
            'delivery' => ['push_status' => $lastPush?->status, 'push_attempts' => $this->pushAttempts()->count(), 'last_push_at' => $lastPush?->attempted_at?->toISOString()],
            'execution' => ['command_status' => $this->status, 'received_at' => $this->received_at?->toISOString(), 'applied_at' => $this->applied_at?->toISOString(), 'failed_at' => $this->failed_at?->toISOString(), 'failure_code' => $this->failure_code],
        ];
    }
}
