<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportDeviceLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['latitude' => ['required', 'numeric', 'between:-90,90'], 'longitude' => ['required', 'numeric', 'between:-180,180'], 'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'captured_at' => ['required', 'date', 'after_or_equal:'.now()->subDay()->toIso8601String(), 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()], 'source' => ['required', Rule::in(['fused', 'last_known', 'current'])]];
    }
}
