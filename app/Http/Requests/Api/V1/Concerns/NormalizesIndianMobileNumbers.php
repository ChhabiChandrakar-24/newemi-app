<?php

namespace App\Http\Requests\Api\V1\Concerns;

trait NormalizesIndianMobileNumbers
{
    protected function normalizeMobileNumbers(): void
    {
        $normalized = [];

        foreach (['mobile_number', 'alternate_mobile_number'] as $field) {
            if (! $this->exists($field) || blank($this->input($field))) {
                continue;
            }

            $digits = preg_replace('/\D+/', '', (string) $this->input($field));
            if (strlen($digits) === 10) {
                $digits = '91'.$digits;
            }

            $normalized[$field] = '+'.$digits;
        }

        $this->merge($normalized);
    }
}
