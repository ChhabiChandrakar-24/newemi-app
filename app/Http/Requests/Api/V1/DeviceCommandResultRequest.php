<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeviceCommandResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'applied' => ['required', 'boolean'],
            'resulting_control_status' => ['nullable', Rule::in(['active', 'warning', 'partial_lock', 'full_lock', 'unlocked', 'closed', 'released'])],
            'result_code' => ['nullable', 'string', 'max:100'], 'result_message' => ['nullable', 'string', 'max:1000'],
            'capability_snapshot' => ['nullable', 'array'], 'capability_snapshot.*' => ['boolean'],
            'policy_version' => ['nullable', 'string', 'max:50'],
            'payload' => ['prohibited'], 'location' => ['prohibited'], 'logs' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $unknown = array_diff(array_keys($this->input('capability_snapshot', [])), config('devices.capabilities'));
            if ($unknown !== []) {
                $validator->errors()->add('capability_snapshot', 'Capability snapshot contains unsupported keys.');
            }
        }];
    }
}
