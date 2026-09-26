<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Device;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_shop_owner_can_view_current_subscription_and_available_plans(): void
    {
        $company = Company::factory()->create([
            'plan' => 'Starter 1 Month',
            'max_devices' => 25,
            'subscription_status' => 'active',
            'expires_at' => now()->addDays(20),
        ]);
        $owner = User::factory()->create(['company_id' => $company->id]);
        $owner->assignRole('admin');

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/v1/subscription/current');
        $response->assertOk()
            ->assertJsonPath('company_id', $company->id)
            ->assertJsonPath('plan_name', 'Starter 1 Month')
            ->assertJsonPath('max_devices', 25)
            ->assertJsonPath('subscription_status', 'active')
            ->assertJsonPath('is_expired', false);

        $plansResponse = $this->getJson('/api/v1/subscription/plans');
        $plansResponse->assertOk()
            ->assertJsonStructure(['plans' => [['id', 'name', 'duration_months', 'device_limit', 'price']]]);
    }

    public function test_shop_owner_can_calculate_custom_quote(): void
    {
        $company = Company::factory()->create();
        $owner = User::factory()->create(['company_id' => $company->id]);
        $owner->assignRole('admin');

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/subscription/quote', [
            'duration_months' => 6,
            'device_limit' => 50,
        ]);

        $response->assertOk()
            ->assertJsonPath('duration_months', 6)
            ->assertJsonPath('device_limit', 50)
            ->assertJsonStructure(['total_price', 'discount_percentage', 'currency']);
    }

    public function test_shop_owner_can_recharge_with_predefined_plan(): void
    {
        $company = Company::factory()->create([
            'plan' => 'Old Plan',
            'max_devices' => 10,
            'expires_at' => now()->subDay(), // expired
        ]);
        $owner = User::factory()->create(['company_id' => $company->id]);
        $owner->assignRole('admin');

        $plan = SubscriptionPlan::first();

        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/v1/subscription/recharge', [
            'plan_id' => $plan->id,
            'payment_method' => 'online_upi',
            'payment_reference' => 'UPI-UTR-99887766',
            'auto_approve' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('recharge.plan_name', $plan->name)
            ->assertJsonPath('recharge.payment_status', 'successful')
            ->assertJsonPath('recharge.recharge_status', 'active');

        $company->refresh();
        $this->assertSame($plan->name, $company->plan);
        $this->assertSame($plan->device_limit, $company->max_devices);
        $this->assertSame('active', $company->subscription_status);
        $this->assertTrue($company->expires_at->isFuture());

        // View history
        $history = $this->getJson('/api/v1/subscription/history');
        $history->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.payment_reference', 'UPI-UTR-99887766');
    }

    public function test_platform_super_admin_can_manage_plans_and_grant_subscriptions(): void
    {
        $superAdmin = User::factory()->create(['is_platform_admin' => true]);

        Sanctum::actingAs($superAdmin);

        // 1. Create plan
        $createPlanResponse = $this->postJson('/api/v1/platform/plans', [
            'name' => 'Custom Retail Plan',
            'code' => 'custom-retail-100',
            'duration_months' => 4,
            'device_limit' => 80,
            'price' => 3200.00,
            'features' => ['80 Devices', '4 Months'],
            'is_active' => true,
        ]);
        $createPlanResponse->assertCreated()
            ->assertJsonPath('plan.name', 'Custom Retail Plan');

        $planId = $createPlanResponse->json('plan.id');

        // 2. Direct grant to a company
        $targetCompany = Company::factory()->create([
            'max_devices' => 5,
            'expires_at' => null,
        ]);

        $grantResponse = $this->postJson("/api/v1/platform/companies/{$targetCompany->id}/grant-subscription", [
            'duration_months' => 5,
            'device_limit' => 120,
            'plan_name' => 'VIP Retailer Grant',
            'notes' => 'Granted for promotional partnership.',
        ]);

        $grantResponse->assertCreated()
            ->assertJsonPath('recharge.device_limit', 120)
            ->assertJsonPath('recharge.payment_method', 'admin_grant');

        $targetCompany->refresh();
        $this->assertSame('VIP Retailer Grant', $targetCompany->plan);
        $this->assertSame(120, $targetCompany->max_devices);
        $this->assertTrue($targetCompany->expires_at->isFuture());

        // 3. Platform recharges index
        $rechargesResponse = $this->getJson('/api/v1/platform/recharges');
        $rechargesResponse->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_device_registration_blocked_when_subscription_expired_or_limit_reached(): void
    {
        // Case A: Subscription Expired
        $expiredCompany = Company::factory()->create([
            'max_devices' => 50,
            'expires_at' => now()->subDays(5),
        ]);
        $owner = User::factory()->create(['company_id' => $expiredCompany->id]);
        $owner->assignRole('admin');
        $customer = Customer::factory()->create(['company_id' => $expiredCompany->id]);

        Sanctum::actingAs($owner);

        $responseExpired = $this->postJson('/api/v1/devices', [
            'customer_id' => $customer->id,
            'brand' => 'Samsung',
            'model' => 'Galaxy S24',
            'imei' => '359876543210987',
        ]);
        $responseExpired->assertStatus(422)
            ->assertJsonValidationErrors(['company']);

        // Case B: Device limit reached
        $limitedCompany = Company::factory()->create([
            'max_devices' => 1,
            'expires_at' => now()->addMonths(3),
        ]);
        $owner2 = User::factory()->create(['company_id' => $limitedCompany->id]);
        $owner2->assignRole('admin');
        $customer2 = Customer::factory()->create(['company_id' => $limitedCompany->id]);

        Sanctum::actingAs($owner2);

        // Register 1st device (succeeds)
        $this->postJson('/api/v1/devices', [
            'customer_id' => $customer2->id,
            'brand' => 'Samsung',
            'model' => 'Galaxy S24',
            'imei' => '359876543210981',
        ])->assertCreated();

        // Register 2nd device (fails limit reached)
        $responseLimit = $this->postJson('/api/v1/devices', [
            'customer_id' => $customer2->id,
            'brand' => 'Apple',
            'model' => 'iPhone 15',
            'imei' => '359876543210982',
        ]);
        $responseLimit->assertStatus(422)
            ->assertJsonValidationErrors(['company']);
    }

    public function test_shop_owner_can_send_recharge_notification_alerts(): void
    {
        $company = Company::factory()->create(['phone' => '9876543210', 'email' => 'owner@shop.com']);
        $owner = User::factory()->create(['company_id' => $company->id]);
        $owner->assignRole('admin');

        $recharge = \App\Models\CompanyRecharge::create([
            'recharge_code' => 'RCH-TEST-001',
            'company_id' => $company->id,
            'plan_name' => 'Growth Plan',
            'duration_months' => 3,
            'device_limit' => 50,
            'amount' => 2499.00,
            'payment_status' => 'successful',
            'recharge_status' => 'active',
        ]);

        Sanctum::actingAs($owner);

        $responseSms = $this->postJson("/api/v1/subscription/history/{$recharge->id}/notify", ['channel' => 'sms']);
        $responseSms->assertOk()
            ->assertJsonPath('channel', 'sms');

        $responseEmail = $this->postJson("/api/v1/subscription/history/{$recharge->id}/notify", ['channel' => 'email']);
        $responseEmail->assertOk()
            ->assertJsonPath('channel', 'email');
    }
}
