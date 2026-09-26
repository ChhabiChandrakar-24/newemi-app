<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'app_version' => ['required', 'string', 'max:50'], 'android_version' => ['required', 'string', 'max:50'],
            'sdk_version' => ['required', 'integer', 'between:21,100'], 'battery_level' => ['nullable', 'integer', 'between:0,100'],
            'battery_charging' => ['nullable', 'boolean'], 'network_type' => ['nullable', Rule::in(['wifi', 'cellular', 'ethernet', 'offline', 'unknown'])],
            'management_mode' => ['required', Rule::in(['unmanaged', 'device_owner', 'device_admin', 'fully_managed', 'dedicated', 'unknown'])],
            'compliance_status' => ['required', Rule::in(['unknown', 'compliant', 'non_compliant', 'attention_required'])],
            'policy_version' => ['nullable', 'string', 'max:50'], 'sim_state' => ['nullable', 'string', 'max:50'],
            'location_permission_state' => ['nullable', Rule::in(['not_requested', 'foreground_granted', 'background_granted', 'denied', 'restricted'])],
            'location_feature_enabled' => ['nullable', 'boolean'],
            'sim_fingerprint' => ['nullable', 'string', 'min:16', 'max:255'], 'capabilities' => ['required', 'array'],
            'enrollment_id' => ['nullable', 'string', 'max:100'],
            'device_id' => ['nullable', 'string', 'max:100'],
            'management_status' => ['nullable', 'string', 'max:50'],
            'device_status' => ['nullable', 'string', 'max:50'],
            'last_sync' => ['nullable', 'string', 'max:100'],
            'battery_status' => ['nullable', 'string', 'max:50'],
            'network_status' => ['nullable', 'string', 'max:50'],
            'security_events' => ['sometimes', 'array', 'max:20'], 'security_events.*.event_type' => ['required', Rule::in(config('devices.event_types'))],
            'security_events.*.severity' => ['required', Rule::in(['info', 'warning', 'high', 'critical'])],
            'security_events.*.metadata' => ['nullable', 'array', 'max:10'],
            'location' => ['prohibited'], 'latitude' => ['prohibited'], 'longitude' => ['prohibited'],
            'imei1' => ['prohibited'], 'imei2' => ['prohibited'], 'serial_number' => ['prohibited'], 'internal_device_uuid' => ['prohibited'],
            'control_status' => ['prohibited'], 'enrollment_status' => ['prohibited'],
        ];
        foreach (config('devices.capabilities') as $capability) {
            $rules["capabilities.{$capability}"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }
}
