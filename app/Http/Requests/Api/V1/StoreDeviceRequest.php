<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'], 'emi_account_id' => ['nullable', 'integer', 'exists:emi_accounts,id'],
            'display_name' => ['nullable', 'string', 'max:255'], 'brand' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:150'], 'manufacturer' => ['nullable', 'string', 'max:100'],
            'invoice_number' => ['nullable', 'string', 'max:100'], 'imei1' => ['nullable', 'string', 'regex:/^[0-9]{14,16}$/', 'unique:devices,imei1'],
            'imei2' => ['nullable', 'string', 'regex:/^[0-9]{14,16}$/', 'unique:devices,imei2'],
            'serial_number' => ['nullable', 'string', 'max:150', 'unique:devices,serial_number'], 'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
