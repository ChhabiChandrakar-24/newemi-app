<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PaymentGateway> */
class PaymentGatewayFactory extends Factory
{
    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id, 'created_by' => User::factory()->state(['company_id' => $company->id])]);
    }

    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::query()->value('id') ?? Company::factory(),
            'webhook_key' => (string) Str::uuid(),
            'provider' => fake()->randomElement(PaymentGateway::PROVIDERS),
            'display_name' => 'Demo '.fake()->company(),
            'environment' => 'test',
            'is_enabled' => false,
            'is_default' => false,
            'public_key' => 'demo_public_identifier',
            'encrypted_secret' => 'demo_secret_not_functional',
            'status' => 'demo',
            'created_by' => User::factory(),
        ];
    }
}
