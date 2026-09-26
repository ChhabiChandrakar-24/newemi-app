<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Device> */
class DeviceFactory extends Factory
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
            'device_code' => 'TEST-'.Str::upper(Str::random(12)),
            'customer_id' => Customer::factory(),
            'display_name' => 'Test Android Device',
            'brand' => 'Example', 'model' => 'Model X', 'manufacturer' => 'Example',
            'internal_device_uuid' => (string) Str::uuid(),
            'management_mode' => 'unmanaged', 'enrollment_status' => 'pending',
            'control_status' => 'active', 'connectivity_status' => 'unknown',
            'compliance_status' => 'unknown', 'created_by' => User::factory(),
        ];
    }
}
