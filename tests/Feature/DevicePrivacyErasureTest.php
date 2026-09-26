<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Device;
use App\Models\EmiAccount;
use App\Services\DevicePrivacyService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DevicePrivacyErasureTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_emi_confirmed_release_erases_device_data_but_retains_customer_and_account(): void
    {
        $company = Company::factory()->create();
        app(TenantContext::class)->set($company);
        $customer = Customer::factory()->forCompany($company)->create();
        $account = EmiAccount::factory()->forCompany($company)->create([
            'customer_id' => $customer->id,
            'status' => 'completed',
            'outstanding_amount' => '0.00',
        ]);
        $device = Device::factory()->forCompany($company)->create([
            'customer_id' => $customer->id,
            'emi_account_id' => $account->id,
            'enrollment_status' => 'enrolled',
            'imei' => '123456789012345',
            'serial_number' => 'PRIVATE-SERIAL',
            'installation_id_hash' => hash('sha256', 'installation'),
            'last_ip_address' => '192.0.2.10',
        ]);
        $device->credentials()->create(['company_id' => $company->id, 'token_hash' => hash('sha256', 'credential'), 'name' => 'agent']);
        $device->enrollments()->create(['company_id' => $company->id, 'enrollment_code' => 'ER-TEST', 'token_hash' => hash('sha256', 'enrollment'), 'status' => 'used', 'expires_at' => now()->addHour()]);
        $device->locations()->create(['company_id' => $company->id, 'latitude' => 20.1, 'longitude' => 81.1, 'captured_at' => now(), 'received_at' => now(), 'source' => 'current', 'tracking_mode' => 'foreground_only']);
        $device->pushTokens()->create(['company_id' => $company->id, 'provider' => 'fcm', 'token_hash' => hash('sha256', 'push'), 'token_encrypted' => encrypt('push'), 'platform' => 'android', 'is_active' => true, 'registered_at' => now()]);

        app(DevicePrivacyService::class)->eraseAfterCompletedEmi($device->id);

        $device->refresh();
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('emi_accounts', ['id' => $account->id, 'status' => 'completed']);
        // Permanent identity and historical relationship MUST be retained
        $this->assertSame($customer->id, $device->customer_id);
        $this->assertSame($account->id, $device->emi_account_id);
        $this->assertSame('123456789012345', $device->imei);
        $this->assertSame('PRIVATE-SERIAL', $device->serial_number);
        $this->assertNotNull($device->internal_device_uuid);
        $this->assertSame('released', $device->enrollment_status);
        $this->assertSame('RELEASED', $device->management_status);
        $this->assertNotNull($device->privacy_erased_at);
        // Active credentials and tokens are revoked
        $this->assertDatabaseMissing('device_api_credentials', ['device_id' => $device->id, 'revoked_at' => null]);
        $this->assertDatabaseMissing('device_push_tokens', ['device_id' => $device->id, 'is_active' => true]);
    }

    public function test_active_emi_never_erases_device_data(): void
    {
        $company = Company::factory()->create();
        app(TenantContext::class)->set($company);
        $account = EmiAccount::factory()->forCompany($company)->create(['status' => 'active']);
        $device = Device::factory()->forCompany($company)->create(['emi_account_id' => $account->id, 'imei' => '123456789012345']);

        app(DevicePrivacyService::class)->eraseAfterCompletedEmi($device->id);

        $this->assertSame('123456789012345', $device->fresh()->imei);
        $this->assertNull($device->fresh()->privacy_erased_at);
    }
}
