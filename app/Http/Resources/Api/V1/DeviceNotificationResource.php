<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceNotificationResource extends JsonResource
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
            'notification_type' => $this->notification_type,
            'recipient_type' => $this->recipient_type,
            'title' => $this->title,
            'message' => $this->message,
            'delivery_status' => $this->delivery_status,
            'channel' => $this->channel,
            'delivered_at' => $this->delivered_at?->toISOString(),
            'deduplication_key' => $this->deduplication_key,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}