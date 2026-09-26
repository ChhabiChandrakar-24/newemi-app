<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'device_code' => $this->device_code, 'internal_device_uuid' => $this->internal_device_uuid,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'customer_code' => $this->customer->customer_code, 'full_name' => $this->customer->full_name, 'mobile_number' => $this->customer->mobile_number] : null),
            'emi_account' => $this->whenLoaded('emiAccount', fn () => $this->emiAccount ? [
                'id' => $this->emiAccount->id,
                'emi_account_code' => $this->emiAccount->emi_account_code,
                'status' => $this->emiAccount->status,
                'emi_status' => $this->emiAccount->emi_status,
                'outstanding_amount' => $this->emiAccount->outstanding_amount,
                'overdue_amount' => $this->emiAccount->overdue_amount,
                'overdue_installments' => $this->emiAccount->overdue_installments,
                'installment_amount' => $this->emiAccount->installment_amount,
                'total_paid' => $this->emiAccount->total_paid,
                'next_due_date' => $this->emiAccount->next_due_date?->toDateString(),
            ] : null),
            'shop' => [
                'id' => $this->company_id,
                'name' => $this->company?->name,
                'code' => $this->company?->company_code,
                'phone' => $this->company?->phone,
                'support_phone' => $this->company?->support_phone ?? $this->company?->phone,
                'email' => $this->company?->email,
                'support_email' => $this->company?->support_email ?? $this->company?->email,
                'address' => trim(($this->company?->address_line_1 ?? '') . ' ' . ($this->company?->city ?? '')),
                'status' => $this->company?->status,
            ],
            'display_name' => $this->display_name, 'brand' => $this->brand, 'model' => $this->model, 'manufacturer' => $this->manufacturer,
            'android_version' => $this->android_version, 'sdk_version' => $this->sdk_version, 'serial_number' => $this->serial_number,
            'imei1' => $this->imei1, 'imei' => $this->imei1, 'imei2' => $this->imei2, 'invoice_number' => $this->invoice_number, 'app_version' => $this->app_version,
            'management_mode' => $this->management_mode, 'enrollment_status' => $this->enrollment_status,
            'management_status' => $this->management_status ?? ($this->enrollment_status === 'released' ? 'RELEASED' : 'MANAGED'),
            'control_status' => $this->control_status, 'desired_control_status' => $this->desired_control_status,
            'connection_status' => $this->connection_status ?? ($this->connectivity_status === 'online' ? 'ONLINE' : ($this->connectivity_status === 'offline' ? 'OFFLINE' : 'UNKNOWN')),
            'device_lock_status' => $this->device_lock_status ?? (in_array($this->control_status, ['full_lock', 'locked']) ? 'LOCKED' : (in_array($this->control_status, ['partial_lock', 'lock_pending']) ? 'LOCK_PENDING' : 'UNLOCKED')),
            'enrollment_id' => $this->enrollments()->latest('id')->value('id'),
            'lock_policy_id' => $this->lock_policy_id, 'policy_automation_paused' => $this->policy_automation_paused,
            'location_tracking_enabled' => $this->location_tracking_enabled, 'location_tracking_mode' => $this->location_tracking_mode,
            'location_permission_state' => $this->location_permission_state,
            'location_consent_given_at' => $this->location_consent_given_at?->toISOString(),
            'location_consent_withdrawn_at' => $this->location_consent_withdrawn_at?->toISOString(),
            'connectivity_status' => $this->connectivity_status,
            'compliance_status' => $this->compliance_status, 'last_seen_at' => $this->last_seen_at?->toISOString(),
            'last_heartbeat_at' => $this->last_heartbeat_at?->toISOString(), 'battery_level' => $this->battery_level,
            'battery_charging' => $this->battery_charging, 'network_type' => $this->network_type, 'sim_state' => $this->sim_state,
            'policy_version' => $this->policy_version, 'capabilities' => $this->capabilities ?? [],
            'consent_verified_at' => $this->consent_verified_at?->toISOString(), 'activated_at' => $this->activated_at?->toISOString(),
            'released_at' => $this->released_at?->toISOString(), 'privacy_erased_at' => $this->privacy_erased_at?->toISOString(), 'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
