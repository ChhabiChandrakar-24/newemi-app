<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class AcceptDeviceConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if ($this->boolean('preview_only') || $this->boolean('preview')) {
            return [
                'enrollment_token' => ['nullable', 'string'],
            ];
        }

        return [
            'enrollment_token' => ['required', 'string'],
            'terms_version' => ['nullable', 'string', 'max:32'],
            'privacy_version' => ['nullable', 'string', 'max:32'],
            'consent_device_id' => ['nullable', 'string', 'max:255'],
            'agent' => ['nullable', 'string', 'max:64'],
            'accepted_terms' => ['required', 'accepted'],
            'accepted_conditions' => ['required', 'accepted'],
            'accepted_privacy' => ['required', 'accepted'],
        ];
    }
}