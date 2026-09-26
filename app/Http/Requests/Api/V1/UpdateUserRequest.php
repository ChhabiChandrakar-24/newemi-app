<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'mobile_number' => ['nullable', 'string', 'regex:/^\+?[1-9]\d{7,14}$/'],
            'password' => ['nullable', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }
}
