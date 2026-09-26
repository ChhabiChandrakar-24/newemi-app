<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\EmiAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EmiAccount> */
class EmiAccountFactory extends Factory
{
    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
            'customer_id' => Customer::factory()->forCompany($company),
            'created_by' => User::factory()->state(['company_id' => $company->id]),
        ]);
    }

    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::query()->value('id') ?? Company::factory(),
            'emi_account_code' => 'TEST-'.Str::upper(Str::random(12)),
            'customer_id' => Customer::factory(),
            'invoice_number' => 'INV-'.Str::upper(Str::random(10)),
            'invoice_date' => today(),
            'product_description' => 'Financed mobile device',
            'financed_amount' => '10000.00',
            'down_payment' => '1000.00',
            'principal_amount' => '9000.00',
            'total_installments' => 3,
            'installment_amount' => '3000.00',
            'emi_start_date' => today()->addMonth(),
            'due_day' => today()->addMonth()->day,
            'grace_period_days' => 3,
            'interest_amount' => '0.00',
            'processing_fee' => '0.00',
            'other_charges' => '0.00',
            'total_payable' => '9000.00',
            'total_paid' => '0.00',
            'outstanding_amount' => '9000.00',
            'overdue_amount' => '0.00',
            'overdue_installments' => 0,
            'next_due_date' => today()->addMonth(),
            'status' => 'draft',
            'auto_lock_enabled' => false,
            'created_by' => User::factory(),
        ];
    }
}
