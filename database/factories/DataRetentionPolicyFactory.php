<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DataRetentionPolicy> */
class DataRetentionPolicyFactory extends Factory
{
    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
        ]);
    }

    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::query()->value('id') ?? Company::factory(),
            'data_type' => 'device_events',
            'retention_period_days' => 90,
            'action' => 'delete',
            'reason' => 'Default retention',
            'is_active' => true,
            'last_applied_at' => null,
        ];
    }
}