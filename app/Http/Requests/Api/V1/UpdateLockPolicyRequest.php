<?php

namespace App\Http\Requests\Api\V1;

class UpdateLockPolicyRequest extends StoreLockPolicyRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach ($rules as &$rule) {
            if ($rule[0] === 'required') {
                $rule[0] = 'sometimes';
            }
        }

        return $rules;
    }
}
