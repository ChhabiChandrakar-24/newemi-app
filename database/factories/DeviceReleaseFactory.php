<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Device;
use App\Models\EmiAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeviceRelease> */
class DeviceReleaseFactory extends Factory
{
    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
            'device_id' => Device::factory()->forCompany($company),
            'customer_id' => Customer::factory()->forCompany($company),
            'released_by' => User::factory()->state(['company_id' => $company->id]),
        ]);
    }

    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::query()->value('id') ?? Company::factory(),
            'device_id' => Device::factory(),
            'customer_id' => null,
            'emi_account_id' => fn () => EmiAccount::factory(),
            'released_by' => null,
            'release_reason' => 'emi_completed',
            'notes' => 'EMI completed; device released from enforcement.',
            'metadata' => null,
            'release_timestamp' => fn () => now(),
        ];
    }
}