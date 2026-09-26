<?php

namespace App\Http\Requests\Api\V1;

use App\Models\DataRetentionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRetentionPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'retention_period_days' => ['required', 'integer', 'min:0', 'max:36500'],
            'action' => ['required', Rule::in(DataRetentionPolicy::ACTIONS)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}