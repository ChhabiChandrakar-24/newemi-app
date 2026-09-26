<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportDeviceEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_type' => ['required', 'string', 'max:100'],
            'severity' => ['required', Rule::in(['info', 'warning', 'critical'])],
            'payload' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}