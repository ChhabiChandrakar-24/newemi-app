<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmiAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date' => ['nullable', 'date'],
            'product_description' => ['nullable', 'string', 'max:255'],
            'financed_amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'down_payment' => ['nullable', 'numeric', 'gte:0', 'lte:financed_amount'],
            'total_installments' => ['required', 'integer', 'min:1', 'max:120'],
            'emi_start_date' => ['required', 'date'],
            'due_day' => ['nullable', 'integer', 'between:1,31'],
            'grace_period_days' => ['nullable', 'integer', 'between:0,90'],
            'interest_amount' => ['nullable', 'numeric', 'gte:0', 'max:9999999999.99'],
            'processing_fee' => ['nullable', 'numeric', 'gte:0', 'max:9999999999.99'],
            'other_charges' => ['nullable', 'numeric', 'gte:0', 'max:9999999999.99'],
            'emi_frequency' => ['nullable', 'string', \Illuminate\Validation\Rule::in(['daily', 'weekly', 'monthly', 'custom_days', 'hourly', 'minutes', 'custom_hours', 'custom_minutes'])],
            'emi_frequency_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'auto_lock_enabled' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['prohibited'],
            'principal_amount' => ['prohibited'],
            'installment_amount' => ['prohibited'],
            'total_payable' => ['prohibited'],
            'total_paid' => ['prohibited'],
            'outstanding_amount' => ['prohibited'],
            'overdue_amount' => ['prohibited'],
            'overdue_installments' => ['prohibited'],
            'next_due_date' => ['prohibited'],
            'last_payment_date' => ['prohibited'],
        ];
    }
}
