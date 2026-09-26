<?php

namespace Tests\Feature\Api\V1;

use App\Models\Company;
use App\Models\CompanyRecharge;
use App\Models\PaymentGateway;
use App\Models\PaymentWebhookLog;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\CompanyRechargeService;
use App\Support\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionQueueAndRevertTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $admin;
    private SubscriptionPlan $plan1;
    private SubscriptionPlan $plan2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::factory()->create([
            'max_devices' => 10,
            'expires_at' => now()->addDays(15),
            'subscription_status' => 'active',
            'plan' => 'Initial Plan',
        ]);

        $this->admin = User::factory()->create(['company_id' => $this->company->id]);
        $this->admin->assignRole('admin');

        $this->plan1 = SubscriptionPlan::create([
            'name' => 'Gold 30-Day Plan',
            'code' => 'GOLD-30',
            'duration_months' => 1,
            'duration_type' => 'days',
            'duration_value' => 30,
            'device_limit' => 25,
            'price' => 1999.00,
            'currency' => 'INR',
            'is_active' => true,
        ]);

        $this->plan2 = SubscriptionPlan::create([
            'name' => 'Diamond 60-Day Plan',
            'code' => 'DIAMOND-60',
            'duration_months' => 2,
            'duration_type' => 'days',
            'duration_value' => 60,
            'device_limit' => 50,
            'price' => 3999.00,
            'currency' => 'INR',
            'is_active' => true,
        ]);
    }

    public function test_purchasing_plan_while_active_places_it_in_queued_status(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/subscription/recharge', [
            'plan_id' => $this->plan1->id,
            'payment_method' => 'online_upi',
            'auto_approve' => true,
            'activate_now' => false,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('company_recharges', [
            'company_id' => $this->company->id,
            'plan_id' => $this->plan1->id,
            'recharge_status' => 'queued',
            'payment_status' => 'successful',
        ]);

        // Company's current plan is still untouched
        $this->company->refresh();
        $this->assertEquals(10, $this->company->max_devices);
    }

    public function test_manual_activation_of_queued_plan_stacks_devices_and_days(): void
    {
        $initialExpiry = $this->company->expires_at;

        // Purchase a queued plan
        $recharge = CompanyRecharge::create([
            'recharge_code' => 'RCH-TEST-QUEUE-1',
            'company_id' => $this->company->id,
            'plan_id' => $this->plan1->id,
            'plan_name' => $this->plan1->name,
            'duration_months' => 1,
            'duration_type' => 'days',
            'duration_value' => 30,
            'device_limit' => 25,
            'amount' => 1999.00,
            'currency' => 'INR',
            'payment_method' => 'razorpay',
            'payment_status' => 'successful',
            'recharge_status' => 'queued',
            'created_by' => $this->admin->id,
        ]);

        // Manually activate it mid-term
        $response = $this->actingAs($this->admin)->postJson("/api/v1/subscription/recharges/{$recharge->id}/activate");
        $response->assertOk();

        $this->company->refresh();
        $recharge->refresh();

        $this->assertEquals('active', $recharge->recharge_status);

        // Stacking check:
        // 1. Devices stacked: initial 10 + plan 25 = 35 devices!
        $this->assertEquals(35, $this->company->max_devices);

        // 2. Validity days stacked: initialExpiry + 30 days!
        $expectedExpiry = $initialExpiry->copy()->addDays(30);
        $this->assertEquals($expectedExpiry->format('Y-m-d H:i'), $this->company->expires_at->format('Y-m-d H:i'));
    }

    public function test_auto_activation_occurs_when_current_subscription_expires(): void
    {
        // Expire the company's current subscription
        $this->company->update([
            'expires_at' => now()->subDay(),
            'subscription_status' => 'expired',
            'max_devices' => 5,
        ]);

        // Create a queued recharge
        $queued = CompanyRecharge::create([
            'recharge_code' => 'RCH-TEST-AUTO-1',
            'company_id' => $this->company->id,
            'plan_id' => $this->plan2->id,
            'plan_name' => $this->plan2->name,
            'duration_months' => 2,
            'duration_type' => 'days',
            'duration_value' => 60,
            'device_limit' => 50,
            'amount' => 3999.00,
            'currency' => 'INR',
            'payment_method' => 'razorpay',
            'payment_status' => 'successful',
            'recharge_status' => 'queued',
            'created_by' => $this->admin->id,
        ]);

        // When getCurrentSubscription is requested, it detects expired status and auto-activates queued
        $service = app(CompanyRechargeService::class);
        $current = $service->getCurrentSubscription($this->company);

        $this->company->refresh();
        $queued->refresh();

        $this->assertEquals('active', $queued->recharge_status);
        $this->assertEquals('active', $this->company->subscription_status);
        $this->assertEquals(50, $this->company->max_devices);
        $this->assertTrue($this->company->expires_at->isFuture());
    }

    public function test_refund_revert_recharge_deducts_active_quota_and_marks_status(): void
    {
        $recharge = CompanyRecharge::create([
            'recharge_code' => 'RCH-TEST-REFUND-1',
            'company_id' => $this->company->id,
            'plan_id' => $this->plan1->id,
            'plan_name' => $this->plan1->name,
            'duration_months' => 1,
            'duration_type' => 'days',
            'duration_value' => 30,
            'device_limit' => 25,
            'amount' => 1999.00,
            'currency' => 'INR',
            'payment_method' => 'admin_grant',
            'payment_reference' => 'TEST-REF-999',
            'payment_status' => 'successful',
            'recharge_status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        // Company had 10 + 25 = 35 devices
        $this->company->update(['max_devices' => 35]);

        // 1. Regular shop admin is forbidden from refunding/reverting
        $forbiddenResponse = $this->actingAs($this->admin)->postJson("/api/v1/subscription/recharges/{$recharge->id}/refund", [
            'reason' => 'Customer requested plan cancellation',
        ]);
        $forbiddenResponse->assertForbidden();

        // 2. System Admin can revert
        $superAdmin = User::factory()->create(['is_platform_admin' => true]);
        $response = $this->actingAs($superAdmin)->postJson("/api/v1/platform/recharges/{$recharge->id}/refund", [
            'reason' => 'Customer requested plan cancellation',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('company_recharges', [
            'id' => $recharge->id,
            'payment_status' => 'refunded',
            'recharge_status' => 'refunded',
        ]);

        $this->company->refresh();
        $this->assertEquals(10, $this->company->max_devices);
    }

    public function test_revert_rejected_after_6_days_and_when_quota_utilized(): void
    {
        $superAdmin = User::factory()->create(['is_platform_admin' => true]);

        // 1. Over 6 days old
        $oldRecharge = CompanyRecharge::create([
            'recharge_code' => 'RCH-TOO-OLD',
            'company_id' => $this->company->id,
            'plan_id' => $this->plan1->id,
            'plan_name' => $this->plan1->name,
            'duration_months' => 1,
            'device_limit' => 25,
            'amount' => 1999.00,
            'payment_method' => 'admin_grant',
            'payment_status' => 'successful',
            'recharge_status' => 'active',
        ]);
        \Illuminate\Support\Facades\DB::table('company_recharges')->where('id', $oldRecharge->id)->update([
            'created_at' => now()->subDays(8),
        ]);
        $oldRecharge->refresh();

        $resOld = $this->actingAs($superAdmin)->postJson("/api/v1/platform/recharges/{$oldRecharge->id}/refund", [
            'reason' => 'Too late',
        ]);
        $resOld->assertStatus(422)
            ->assertJsonValidationErrors(['recharge']);

        // 2. Plan quota actively utilized by enrolled devices
        $activeRecharge = CompanyRecharge::create([
            'recharge_code' => 'RCH-USED-PLAN',
            'company_id' => $this->company->id,
            'plan_id' => $this->plan1->id,
            'plan_name' => $this->plan1->name,
            'duration_months' => 1,
            'device_limit' => 20,
            'amount' => 1999.00,
            'payment_method' => 'admin_grant',
            'payment_status' => 'successful',
            'recharge_status' => 'active',
            'created_at' => now()->subDays(2),
        ]);

        $this->company->update(['max_devices' => 20]);
        // Simulate 15 enrolled devices
        \App\Models\Device::factory()->count(15)->create([
            'company_id' => $this->company->id,
            'enrollment_status' => 'enrolled',
        ]);

        $resUsed = $this->actingAs($superAdmin)->postJson("/api/v1/platform/recharges/{$activeRecharge->id}/refund", [
            'reason' => 'Try refund with devices',
        ]);
        $resUsed->assertStatus(422)
            ->assertJsonValidationErrors(['recharge']);
    }

    public function test_webhook_logs_incoming_razorpay_events(): void
    {
        $gateway = PaymentGateway::factory()->create([
            'company_id' => $this->company->id,
            'provider' => 'razorpay',
            'is_enabled' => true,
            'encrypted_webhook_secret' => 'whsec_test123',
        ]);

        $payload = json_encode([
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => 'pay_test_wh_123',
                        'order_id' => 'order_test_wh_456',
                        'amount' => 199900,
                        'currency' => 'INR',
                        'notes' => ['company_id' => (string) $this->company->id],
                    ],
                ],
            ],
        ]);

        $signature = hash_hmac('sha256', $payload, 'whsec_test123');

        $response = $this->call('POST', '/api/webhooks/payments/razorpay', [], [], [], [
            'HTTP_X_GATEWAY_KEY' => $gateway->webhook_key,
            'HTTP_X_PAYMENT_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(202);

        $this->assertDatabaseHas('payment_webhook_logs', [
            'provider' => 'razorpay',
            'event' => 'payment.captured',
            'payment_id' => 'pay_test_wh_123',
            'order_id' => 'order_test_wh_456',
            'status' => 'processed',
        ]);
    }

    public function test_manual_activation_with_switch_mode_replaces_primary_plan(): void
    {
        $oldRecharge = CompanyRecharge::create([
            'recharge_code' => 'RCH-OLD-ACTIVE',
            'company_id' => $this->company->id,
            'plan_id' => $this->plan1->id,
            'plan_name' => $this->plan1->name,
            'duration_months' => 1,
            'duration_type' => 'days',
            'duration_value' => 30,
            'device_limit' => 25,
            'amount' => 1999.00,
            'currency' => 'INR',
            'payment_method' => 'online_upi',
            'payment_status' => 'successful',
            'recharge_status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $queued = CompanyRecharge::create([
            'recharge_code' => 'RCH-NEW-SWITCH',
            'company_id' => $this->company->id,
            'plan_id' => $this->plan2->id,
            'plan_name' => $this->plan2->name,
            'duration_months' => 2,
            'duration_type' => 'days',
            'duration_value' => 60,
            'device_limit' => 50,
            'amount' => 3999.00,
            'currency' => 'INR',
            'payment_method' => 'online_upi',
            'payment_status' => 'successful',
            'recharge_status' => 'queued',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->postJson("/api/v1/subscription/recharges/{$queued->id}/activate", [
            'mode' => 'switch',
        ]);

        $response->assertOk();
        $this->company->refresh();
        $oldRecharge->refresh();
        $queued->refresh();

        $this->assertEquals('superseded', $oldRecharge->recharge_status);
        $this->assertEquals('active', $queued->recharge_status);
        $this->assertEquals(50, $this->company->max_devices);
        $this->assertEquals($this->plan2->name, $this->company->plan);
    }

    public function test_pause_and_resume_subscription_plan(): void
    {
        $recharge = CompanyRecharge::create([
            'recharge_code' => 'RCH-PAUSE-TEST',
            'company_id' => $this->company->id,
            'plan_id' => $this->plan1->id,
            'plan_name' => $this->plan1->name,
            'duration_months' => 1,
            'duration_type' => 'days',
            'duration_value' => 30,
            'device_limit' => 25,
            'amount' => 1999.00,
            'currency' => 'INR',
            'payment_method' => 'online_upi',
            'payment_status' => 'successful',
            'recharge_status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        // Pause
        $pauseRes = $this->actingAs($this->admin)->postJson("/api/v1/subscription/recharges/{$recharge->id}/pause");
        $pauseRes->assertOk();
        $recharge->refresh();
        $this->assertEquals('paused', $recharge->recharge_status);
        $this->assertNotNull($recharge->paused_at);

        // Resume
        $resumeRes = $this->actingAs($this->admin)->postJson("/api/v1/subscription/recharges/{$recharge->id}/resume");
        $resumeRes->assertOk();
        $recharge->refresh();
        $this->assertEquals('active', $recharge->recharge_status);
        $this->assertNull($recharge->paused_at);
    }

    public function test_transfer_queued_recharge_requires_admin_approval(): void
    {
        $targetCompany = Company::factory()->create([
            'name' => 'City Mobile Hub',
            'company_code' => 'CMP-CITY-99',
            'max_devices' => 5,
        ]);

        $queued = CompanyRecharge::create([
            'recharge_code' => 'RCH-TRANSFER-ME',
            'company_id' => $this->company->id,
            'plan_id' => $this->plan1->id,
            'plan_name' => $this->plan1->name,
            'duration_months' => 1,
            'duration_type' => 'days',
            'duration_value' => 30,
            'device_limit' => 25,
            'amount' => 1999.00,
            'currency' => 'INR',
            'payment_method' => 'razorpay',
            'payment_status' => 'successful',
            'recharge_status' => 'queued',
            'created_by' => $this->admin->id,
        ]);

        // 1. Shop admin requests transfer
        $response = $this->actingAs($this->admin)->postJson("/api/v1/subscription/recharges/{$queued->id}/transfer", [
            'target_company' => 'CMP-CITY-99',
            'notes' => 'Transferred for partner store branch',
        ]);

        $response->assertOk();
        $queued->refresh();

        $this->assertEquals('transfer_pending', $queued->recharge_status);
        $this->assertEquals('pending_approval', $queued->transfer_status);
        $this->assertEquals($targetCompany->id, $queued->transferred_to_company_id);

        // 2. System Admin approves the transfer
        $superAdmin = User::factory()->create(['is_platform_admin' => true]);
        $approveRes = $this->actingAs($superAdmin)->postJson("/api/v1/platform/recharges/{$queued->id}/approve-transfer");
        $approveRes->assertOk();

        $queued->refresh();
        $this->assertEquals('transferred', $queued->recharge_status);
        $this->assertEquals('approved', $queued->transfer_status);

        $this->assertDatabaseHas('company_recharges', [
            'company_id' => $targetCompany->id,
            'plan_name' => $this->plan1->name,
            'device_limit' => 25,
            'recharge_status' => 'queued',
            'payment_status' => 'successful',
            'transferred_from_company_id' => $this->company->id,
        ]);
    }

    public function test_expiring_alert_returned_in_current_subscription(): void
    {
        // Set expiry to 2 days from now
        $this->company->update([
            'expires_at' => now()->addDays(2),
        ]);

        $service = app(CompanyRechargeService::class);
        $sub = $service->getCurrentSubscription($this->company);

        $this->assertTrue($sub['expiring_alert']['show_popup']);
        $this->assertEquals(2, $sub['expiring_alert']['days_remaining']);
        $this->assertFalse($sub['expiring_alert']['is_expired']);
    }

    public function test_platform_admin_can_access_dedicated_webhooks_module(): void
    {
        $superAdmin = User::factory()->create(['is_platform_admin' => true]);

        // Populate a webhook record
        PaymentWebhookLog::create([
            'provider' => 'razorpay',
            'event' => 'payment.captured',
            'payment_id' => 'pay_test_platform_1',
            'order_id' => 'order_test_1',
            'status' => 'processed',
            'payload' => ['test' => true],
        ]);

        // Regular user is forbidden
        $this->actingAs($this->admin)->getJson('/api/v1/platform/webhooks')->assertForbidden();

        // Platform admin succeeds
        $res = $this->actingAs($superAdmin)->getJson('/api/v1/platform/webhooks');
        $res->assertOk()
            ->assertJsonStructure(['logs', 'stats'])
            ->assertJsonPath('stats.total', 1)
            ->assertJsonPath('stats.processed', 1);
    }
}
