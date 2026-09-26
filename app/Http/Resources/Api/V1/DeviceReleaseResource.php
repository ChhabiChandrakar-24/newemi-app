<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'certificate_number' => 'REL-' . ($this->release_timestamp?->format('Y') ?? now()->format('Y')) . '-' . str_pad((string) $this->id, 6, '0', STR_PAD_LEFT),
            'device' => $this->whenLoaded('device', fn () => $this->device ? [
                'id' => $this->device->id,
                'device_code' => $this->device->device_code,
                'display_name' => $this->device->display_name,
                'brand' => $this->device->brand,
                'model' => $this->device->model,
                'imei1' => $this->device->imei1,
                'imei2' => $this->device->imei2,
                'serial_number' => $this->device->serial_number,
                'enrollment_status' => $this->device->enrollment_status,
                'control_status' => $this->device->control_status,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'customer_code' => $this->customer->customer_code,
                'full_name' => $this->customer->full_name,
                'mobile_number' => $this->customer->mobile_number,
                'email' => $this->customer->email,
                'city' => $this->customer->city,
                'state' => $this->customer->state,
            ] : null),
            'emi_account' => $this->whenLoaded('emiAccount', fn () => $this->emiAccount ? [
                'id' => $this->emiAccount->id,
                'emi_account_code' => $this->emiAccount->emi_account_code,
                'status' => $this->emiAccount->status,
                'emi_status' => $this->emiAccount->emi_status,
                'principal_amount' => $this->emiAccount->principal_amount,
                'total_payable' => $this->emiAccount->total_payable,
                'total_paid' => $this->emiAccount->total_paid,
                'outstanding_amount' => $this->emiAccount->outstanding_amount,
                'invoice_number' => $this->emiAccount->invoice_number,
            ] : null),
            'released_by_user' => $this->whenLoaded('releasedByUser', fn () => $this->releasedByUser ? [
                'id' => $this->releasedByUser->id,
                'name' => $this->releasedByUser->name,
                'email' => $this->releasedByUser->email,
            ] : null),
            'customer_id' => $this->customer_id,
            'emi_account_id' => $this->emi_account_id,
            'released_by' => $this->released_by,
            'release_reason' => $this->release_reason,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
            'release_timestamp' => $this->release_timestamp?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}