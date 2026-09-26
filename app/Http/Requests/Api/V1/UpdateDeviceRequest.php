<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'emi_account_id' => ['nullable', 'integer', 'exists:emi_accounts,id'], 'display_name' => ['nullable', 'string', 'max:255'],
            'invoice_number' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:5000'],
            'customer_id' => ['prohibited'], 'management_mode' => ['prohibited'], 'enrollment_status' => ['prohibited'],
            'control_status' => ['prohibited'], 'connectivity_status' => ['prohibited'], 'compliance_status' => ['prohibited'],
            'last_seen_at' => ['prohibited'], 'last_heartbeat_at' => ['prohibited'], 'app_version' => ['prohibited'], 'capabilities' => ['prohibited'],
        ];
    }
}
