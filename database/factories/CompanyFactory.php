<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_code' => 'CMP-'.Str::upper(Str::random(10)),
            'name' => fake()->company(),
            'status' => 'active',
            'subscription_status' => 'active',
            'activated_at' => now(),
        ];
    }
}
