<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLockPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'], 'is_default' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
            'warning_after_overdue_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'partial_lock_after_overdue_days' => ['nullable', 'integer', 'gte:warning_after_overdue_days', 'max:3650'],
            'full_lock_after_overdue_days' => ['nullable', 'integer', 'max:3650'],
            'unlock_on_payment_clearance' => ['sometimes', 'boolean'], 'grace_period_override' => ['nullable', 'integer', 'min:0', 'max:365'],
            'offline_behavior' => ['sometimes', Rule::in(['defer', 'queue_until_online'])],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $partial = $this->filled('partial_lock_after_overdue_days') ? $this->integer('partial_lock_after_overdue_days') : null;
            $full = $this->filled('full_lock_after_overdue_days') ? $this->integer('full_lock_after_overdue_days') : null;
            if ($partial !== null && $full !== null && $full < $partial) {
                $validator->errors()->add('full_lock_after_overdue_days', 'Full lock threshold must be at or after partial lock.');
            }
        }];
    }
}
