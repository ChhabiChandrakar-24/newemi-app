<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = ['idempotency_key' => $this->header('Idempotency-Key')];
        if (! $this->has('emi_account_id')) {
            $accountParam = $this->route('emiAccount') ?? $this->route('id');
            if ($accountParam) {
                $merge['emi_account_id'] = $accountParam instanceof \App\Models\EmiAccount ? $accountParam->id : $accountParam;
            }
        }
        if (! $this->has('payment_type')) {
            $merge['payment_type'] = 'emi';
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'emi_account_id' => ['required', 'integer', 'exists:emi_accounts,id'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'payment_method' => ['required', Rule::in(['cash', 'upi', 'bank_transfer', 'card', 'cheque', 'other'])],
            'transaction_reference' => ['nullable', 'string', 'max:150'],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'payment_type' => ['required', Rule::in(['emi', 'advance', 'partial', 'adjustment'])],
            'status' => ['sometimes', Rule::in(['pending', 'verified'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'customer_id' => ['prohibited'],
            'receipt_number' => ['prohibited'],
            'verified_by' => ['prohibited'],
            'verified_at' => ['prohibited'],
        ];
    }
}
