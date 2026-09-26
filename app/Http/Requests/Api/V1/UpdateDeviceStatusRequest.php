<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'control_status' => ['sometimes', Rule::in(['active', 'warning', 'partial_lock', 'full_lock', 'unlocked', 'closed'])],
            'enrollment_status' => ['sometimes', Rule::in(['failed', 'suspended'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->hasAny(['control_status', 'enrollment_status'])) {
                $validator->errors()->add('status', 'At least one status field is required.');
            }
        });
    }
}
