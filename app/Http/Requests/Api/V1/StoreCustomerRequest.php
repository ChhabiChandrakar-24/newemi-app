<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\NormalizesIndianMobileNumbers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    use NormalizesIndianMobileNumbers;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeMobileNumbers();
    }

    public function rules(): array
    {
        return $this->customerRules();
    }

    private function customerRules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'mobile_number' => ['required', 'string', 'regex:/^\+91[6-9]\d{9}$/'],
            'alternate_mobile_number' => ['nullable', 'string', 'different:mobile_number', 'regex:/^\+91[6-9]\d{9}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', Rule::when(
                strcasecmp((string) $this->input('country', 'India'), 'India') === 0,
                ['regex:/^[1-9][0-9]{5}$/'],
                ['max:20'],
            )],
            'country' => ['sometimes', 'string', 'max:100'],
            'identity_type' => ['nullable', 'string', 'max:50', 'regex:/^[\pL\pN .\/-]+$/u'],
            'identity_number' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9 .\/-]+$/'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'closed'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'consent_given' => ['sometimes', 'boolean'],
        ];
    }
}
