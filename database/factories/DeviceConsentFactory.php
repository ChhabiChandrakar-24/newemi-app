<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Device;
use App\Models\EmiAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DeviceConsent> */
class DeviceConsentFactory extends Factory
{
    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
            'customer_id' => Customer::factory()->forCompany($company),
            'device_id' => Device::factory()->forCompany($company),
        ]);
    }

    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::query()->value('id') ?? Company::factory(),
            'customer_id' => Customer::factory(),
            'device_id' => null,
            'enrollment_id' => null,
            'emi_account_id' => fn () => EmiAccount::factory()->forCompany(Company::query()->find($this->company_id) ?? Company::factory()),
            'consent_status' => 'accepted',
            'consent_timestamp' => fn () => now(),
            'terms_version' => '1.0',
            'privacy_version' => '1.0',
            'consent_device_id' => 'TEST-'.Str::upper(Str::random(12)),
            'consent_ip_address' => '127.0.0.1',
            'enrollment_timestamp' => null,
            'accepted_terms' => true,
            'accepted_conditions' => true,
            'accepted_privacy' => true,
            'metadata' => null,
            'withdrawn_at' => null,
        ];
    }
}