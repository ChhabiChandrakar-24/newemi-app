<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettleEmiAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            'settlement_amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'discount_amount' => ['sometimes', 'decimal:0,2', 'gte:0', 'max:9999999999.99'],
            'payment_method' => ['required', Rule::in(['cash', 'upi', 'bank_transfer', 'card', 'cheque', 'other'])],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'transaction_reference' => ['nullable', 'string', 'max:150'],
            'remarks' => ['required', 'string', 'min:5', 'max:2000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
