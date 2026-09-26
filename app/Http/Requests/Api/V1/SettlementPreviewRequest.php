<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SettlementPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['discount_amount' => ['sometimes', 'decimal:0,2', 'gte:0', 'max:9999999999.99']];
    }
}
