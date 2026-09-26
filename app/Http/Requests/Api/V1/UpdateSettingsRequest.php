<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') === true;
    }

    public function rules(): array
    {
        return ['values' => ['present', 'array', 'max:100'], 'values.*' => ['nullable'], 'remove_secrets' => ['sometimes', 'array'], 'remove_secrets.*' => ['string', 'max:100']];
    }
}
