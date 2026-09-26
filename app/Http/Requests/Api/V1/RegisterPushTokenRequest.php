<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterPushTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['provider' => ['required', Rule::in(['fcm'])], 'token' => ['required', 'string', 'min:20', 'max:4096'], 'app_version' => ['nullable', 'string', 'max:50'], 'platform' => ['required', Rule::in(['android'])]];
    }
}
