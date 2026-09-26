<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateDeviceCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key') ?: $this->input('idempotency_key')]);
    }

    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:1000'], 'reason' => ['nullable', 'string', 'max:500'],
            'expires_in_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'], 'customer_display_name' => ['nullable', 'string', 'max:150'],
            'support_phone' => ['nullable', 'string', 'max:30'], 'support_contact' => ['nullable', 'string', 'max:150'],
            'message' => ['nullable', 'string', 'max:500'], 'support_message' => ['nullable', 'string', 'max:500'],
            'policy_version' => ['nullable', 'string', 'max:50'],
            'allowed_packages' => ['nullable', 'array', 'max:20'],
            'allowed_packages.*' => ['string', 'max:200', 'regex:/^[A-Za-z][A-Za-z0-9_.]+$/'],
            'restrictions' => ['nullable', 'array'],
            'restrictions.restrict_factory_reset' => ['sometimes', 'boolean'],
            'restrictions.restrict_safe_boot' => ['sometimes', 'boolean'],
            'restrictions.restrict_user_changes' => ['sometimes', 'boolean'],
            'restrictions.restrict_unknown_sources' => ['sometimes', 'boolean'],
            'priority' => ['prohibited'], 'payload' => ['prohibited'], 'command' => ['prohibited'], 'intent' => ['prohibited'], 'url' => ['prohibited'],
        ];
    }
}
