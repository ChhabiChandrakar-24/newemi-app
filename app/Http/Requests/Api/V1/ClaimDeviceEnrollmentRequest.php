<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClaimDeviceEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'enrollment_token' => ['required', 'string', 'max:255'], 'installation_identifier' => ['required', 'string', 'min:16', 'max:255'],
            'brand' => ['nullable', 'string', 'max:100'], 'model' => ['nullable', 'string', 'max:150'], 'manufacturer' => ['nullable', 'string', 'max:100'],
            'android_version' => ['required', 'string', 'max:50'], 'sdk_version' => ['required', 'integer', 'between:21,100'],
            'app_version' => ['required', 'string', 'max:50'], 'management_mode' => ['required', Rule::in(['unmanaged', 'device_owner', 'device_admin', 'fully_managed', 'dedicated', 'unknown'])],
            'capabilities' => ['required', 'array'],
        ];
        foreach (config('devices.capabilities') as $capability) {
            $rules["capabilities.{$capability}"] = ['sometimes', 'boolean'];
        }

        return $rules;
    }
}
