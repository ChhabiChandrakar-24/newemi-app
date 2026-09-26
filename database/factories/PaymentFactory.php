<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EmiAccount;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    public function forCompany(Company $company): static
    {
        return $this->state(function () use ($company): array {
            $account = EmiAccount::factory()->forCompany($company)->create();

            return [
                'company_id' => $company->id, 'emi_account_id' => $account->id, 'customer_id' => $account->customer_id,
                'collected_by' => User::factory()->state(['company_id' => $company->id]),
                'created_by' => User::factory()->state(['company_id' => $company->id]),
            ];
        });
    }

    public function definition(): array
    {
        $account = EmiAccount::factory()->create();

        return [
            'company_id' => fn () => Company::query()->value('id') ?? Company::factory(),
            'payment_code' => 'TEST-'.Str::upper(Str::random(12)),
            'emi_account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'payment_date' => today(),
            'amount' => '1000.00',
            'payment_method' => 'cash',
            'payment_type' => 'emi',
            'status' => 'pending',
            'collected_by' => User::factory(),
            'created_by' => User::factory(),
        ];
    }
}
