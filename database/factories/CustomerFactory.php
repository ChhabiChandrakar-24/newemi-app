<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id, 'created_by' => User::factory()->state(['company_id' => $company->id])]);
    }

    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::query()->value('id') ?? Company::factory(),
            'customer_code' => 'TEST-'.Str::upper(Str::random(12)),
            'full_name' => fake()->name(),
            'mobile_number' => '+91'.fake()->numberBetween(6000000000, 9999999999),
            'alternate_mobile_number' => null,
            'email' => fake()->optional()->safeEmail(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'postal_code' => (string) fake()->numberBetween(110001, 855117),
            'country' => 'India',
            'status' => 'active',
            'consent_given' => false,
            'consent_given_at' => null,
            'created_by' => User::factory(),
        ];
    }
}
