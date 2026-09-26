<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DeviceNotification> */
class DeviceNotificationFactory extends Factory
{
    public function forCompany(Company $company): static
    {
        return $this->state(fn () => [
            'company_id' => $company->id,
            'device_id' => Device::factory()->forCompany($company),
            'customer_id' => Customer::factory()->forCompany($company),
        ]);
    }

    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::query()->value('id') ?? Company::factory(),
            'device_id' => null,
            'customer_id' => Customer::factory(),
            'emi_account_id' => null,
            'notification_type' => 'lifecycle',
            'recipient_type' => 'device',
            'title' => 'Device status changed',
            'message' => 'Your device management status was updated.',
            'delivery_status' => 'sent',
            'channel' => 'push',
            'delivered_at' => fn () => now(),
            'deduplication_key' => fn () => 'test-'.Str::uuid(),
            'metadata' => null,
        ];
    }
}