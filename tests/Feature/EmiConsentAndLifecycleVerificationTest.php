<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DataRetentionPolicy;
use App\Models\Device;
use App\Models\DeviceConsent;
use App\Models\DeviceEnrollment;
use App\Models\DeviceEvent;
use App\Models\DeviceNotification;
use App\Models\DeviceRelease;
use App\Models\EmiAccount;
use App\Models\EmiSchedule;
use App\Models\Payment;
use App\Models\User;
use App\Services\EmiLifecycleService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmiConsentAndLifecycleVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $staff;
    protected Customer $customer;
    protected Device $device;
    protected EmiAccount $emiAccount;
    protected DeviceEnrollment $enrollment;
    protected string $plainEnrollmentToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::factory()->create();
        $this->staff = User::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'active',
        ]);
        $this->staff->assignRole('manager');

        $this->customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'full_name' => 'Rajesh Sharma',
            'mobile_number' => '+919876543210',
        ]);

        $this->emiAccount = EmiAccount::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'principal_amount' => 30000,
            'financed_amount' => 30000,
            'installment_amount' => 5000,
            'total_installments' => 6,
            'total_payable' => 30000,
            'total_paid' => 10000,
            'outstanding_amount' => 20000,
            'overdue_amount' => 0,
            'grace_period_days' => 5,
            'next_due_date' => Carbon::now()->addDays(10),
            'status' => 'active',
            'emi_status' => 'ACTIVE',
        ]);

        for ($i = 1; $i <= 4; $i++) {
            EmiSchedule::create([
                'emi_account_id' => $this->emiAccount->id,
                'installment_number' => $i,
                'due_date' => Carbon::now()->addDays($i * 30),
                'opening_balance' => 25000 - ($i * 5000),
                'principal_due' => 5000,
                'interest_due' => 0,
                'installment_amount' => 5000,
                'paid_amount' => 0,
                'outstanding_amount' => 5000,
                'overdue_amount' => 0,
                'status' => 'due',
            ]);
        }

        $this->device = Device::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'emi_account_id' => $this->emiAccount->id,
            'brand' => 'Samsung',
            'model' => 'Galaxy M34',
            'management_status' => 'ENROLLMENT_PENDING',
            'enrollment_status' => 'pending',
            'control_status' => 'normal',
        ]);

        $this->plainEnrollmentToken = 'TEST-ENROLLMENT-TOKEN-' . bin2hex(random_bytes(16));
        $this->enrollment = DeviceEnrollment::create([
            'company_id' => $this->company->id,
            'device_id' => $this->device->id,
            'enrollment_code' => 'ENR-' . strtoupper(bin2hex(random_bytes(4))),
            'token_hash' => hash('sha256', $this->plainEnrollmentToken),
            'status' => 'pending',
            'expires_at' => Carbon::now()->addHours(24),
            'created_by' => $this->staff->id,
        ]);
    }

    /**
     * Section 4: Consent preview returns Customer, Device, EMI, Terms, Privacy, Conditions.
     */
    public function test_consent_preview_returns_disclosures_and_contract_details(): void
    {
        $response = $this->postJson('/api/device/consent', [
            'enrollment_token' => $this->plainEnrollmentToken,
            'preview_only' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.customer.name', 'Rajesh Sharma')
            ->assertJsonPath('data.customer.mobile', '+919876543210')
            ->assertJsonPath('data.device.brand', 'Samsung')
            ->assertJsonPath('data.emi.installment_amount', '5000.00')
            ->assertJsonPath('data.emi.outstanding_amount', '20000.00')
            ->assertJsonPath('data.emi.grace_period_days', 5)
            ->assertJsonStructure([
                'data' => [
                    'customer', 'device', 'emi', 'conditions', 'terms_version', 'privacy_version', 'terms_text', 'privacy_text',
                ],
            ]);
    }

    /**
     * Section 4: Enrollment is BLOCKED if any of accepted_terms, accepted_conditions, accepted_privacy is false.
     */
    public function test_consent_enforces_all_three_mandatory_flags(): void
    {
        // 1. Missing/false accepted_terms
        $this->postJson('/api/device/consent', [
            'enrollment_token' => $this->plainEnrollmentToken,
            'accepted_terms' => false,
            'accepted_conditions' => true,
            'accepted_privacy' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('accepted_terms');

        // 2. Missing/false accepted_conditions
        $this->postJson('/api/device/consent', [
            'enrollment_token' => $this->plainEnrollmentToken,
            'accepted_terms' => true,
            'accepted_conditions' => false,
            'accepted_privacy' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('accepted_conditions');

        // 3. Missing/false accepted_privacy
        $this->postJson('/api/device/consent', [
            'enrollment_token' => $this->plainEnrollmentToken,
            'accepted_terms' => true,
            'accepted_conditions' => true,
            'accepted_privacy' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('accepted_privacy');

        // 4. All three true: Accepted and stored with timestamp & versions
        $acceptResponse = $this->postJson('/api/device/consent', [
            'enrollment_token' => $this->plainEnrollmentToken,
            'accepted_terms' => true,
            'accepted_conditions' => true,
            'accepted_privacy' => true,
            'device_brand' => 'Samsung',
            'device_model' => 'Galaxy M34',
        ]);

        $acceptResponse->assertStatus(201)
            ->assertJsonPath('data.consent_status', 'accepted')
            ->assertJsonPath('data.accepted_terms', true)
            ->assertJsonPath('data.accepted_conditions', true)
            ->assertJsonPath('data.accepted_privacy', true);

        $this->assertDatabaseHas('device_consents', [
            'device_id' => $this->device->id,
            'customer_id' => $this->customer->id,
            'consent_status' => 'accepted',
            'accepted_terms' => true,
            'accepted_conditions' => true,
            'accepted_privacy' => true,
        ]);

        $this->assertNotNull($this->device->fresh()->consent_verified_at);
    }

    /**
     * Section 3 & 15: EMI Lifecycle transitions and network failure safety.
     * Offline/network failures NEVER automatically lock a device.
     */
    public function test_emi_lifecycle_and_offline_safety(): void
    {
        // 1. Initial ACTIVE status
        $this->assertEquals('ACTIVE', $this->emiAccount->emi_status);
        $this->assertEquals('normal', $this->device->control_status);

        // 2. Offline / missed heartbeat does NOT cause device locking
        $this->device->update([
            'last_seen_at' => Carbon::now()->subHours(72),
            'connectivity_status' => 'offline',
            'is_online' => false,
        ]);

        app(EmiLifecycleService::class)->syncAccount($this->emiAccount->fresh());
        $this->assertEquals('normal', $this->device->fresh()->control_status);

        // 3. Past due within grace period -> GRACE_PERIOD
        $schedule1 = $this->emiAccount->schedules()->first();
        $schedule1->update([
            'due_date' => Carbon::now()->subDays(2),
            'status' => 'due',
        ]);
        $this->emiAccount->update([
            'next_due_date' => Carbon::now()->subDays(2),
            'grace_period_days' => 5,
        ]);
        app(EmiLifecycleService::class)->syncAccount($this->emiAccount->fresh());
        $this->assertEquals('GRACE_PERIOD', $this->emiAccount->fresh()->emi_status);
        $this->assertEquals('normal', $this->device->fresh()->control_status);

        // 4. Past grace period -> WARNING (eligible for restriction)
        $schedule1->update([
            'due_date' => Carbon::now()->subDays(10),
            'status' => 'due',
        ]);
        $this->emiAccount->update([
            'next_due_date' => Carbon::now()->subDays(10),
            'grace_period_days' => 5,
        ]);
        app(EmiLifecycleService::class)->syncAccount($this->emiAccount->fresh());
        $this->assertEquals('WARNING', $this->emiAccount->fresh()->emi_status);

        // 5. Authorized restriction applied -> RESTRICTED / LOCKED
        $this->device->update(['control_status' => 'partial_locked']);
        app(EmiLifecycleService::class)->syncAccount($this->emiAccount->fresh());
        $this->assertEquals('RESTRICTED', $this->emiAccount->fresh()->emi_status);

        $this->device->update(['control_status' => 'full_locked']);
        app(EmiLifecycleService::class)->syncAccount($this->emiAccount->fresh());
        $this->assertEquals('LOCKED', $this->emiAccount->fresh()->emi_status);

        // 6. Payment made -> Restriction lifted, status restored
        $schedule1->update([
            'due_date' => Carbon::now()->addDays(30),
            'status' => 'due',
        ]);
        $this->device->update(['control_status' => 'normal']);
        $this->emiAccount->update([
            'next_due_date' => Carbon::now()->addDays(30),
            'overdue_amount' => 0,
            'overdue_installments' => 0,
        ]);
        app(EmiLifecycleService::class)->syncAccount($this->emiAccount->fresh());
        $this->assertEquals('ACTIVE', $this->emiAccount->fresh()->emi_status);
    }

    /**
     * Section 8: Final EMI payment automatically releases device and creates audit trail.
     */
    public function test_final_emi_payment_automatically_releases_device(): void
    {
        Sanctum::actingAs($this->staff);

        // Current outstanding is 20000. Pay remaining 20000 in full with verified status.
        $response = $this->postJson("/api/emi/{$this->emiAccount->id}/payment", [
            'amount' => 20000,
            'payment_date' => Carbon::now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'status' => 'verified',
            'transaction_reference' => 'TXN-FINAL-PAYMENT-FULL',
        ]);

        $response->assertCreated();

        $freshAccount = $this->emiAccount->fresh();
        $freshDevice = $this->device->fresh();

        // 1. EMI status becomes COMPLETED/paid
        $this->assertEquals(0, (float) $freshAccount->outstanding_amount);
        $this->assertTrue(in_array($freshAccount->status, ['completed', 'paid']));

        // 2. Device status becomes RELEASED
        $this->assertEquals('RELEASED', $freshDevice->management_status);
        $this->assertEquals('released', $freshDevice->enrollment_status);

        // 3. DeviceRelease record exists
        $this->assertDatabaseHas('device_releases', [
            'device_id' => $this->device->id,
            'customer_id' => $this->customer->id,
            'release_reason' => 'emi_completed',
        ]);

        // 4. Notification sent
        $this->assertDatabaseHas('device_notifications', [
            'device_id' => $this->device->id,
            'notification_type' => 'DEVICE_RELEASED',
        ]);
    }

    /**
     * Section 9: Data retention prunes ephemeral records while strictly preserving financial/audit/consent records.
     */
    public function test_data_retention_preserves_financial_and_consent_records(): void
    {
        // 1. Create a historical consent record
        $consent = DeviceConsent::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'device_id' => $this->device->id,
            'emi_account_id' => $this->emiAccount->id,
            'enrollment_id' => $this->enrollment->id,
            'consent_status' => 'accepted',
            'accepted_terms' => true,
            'accepted_conditions' => true,
            'accepted_privacy' => true,
            'consent_timestamp' => Carbon::now()->subMonths(6),
        ]);

        // 2. Create a financial payment record
        $payment = Payment::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'emi_account_id' => $this->emiAccount->id,
            'payment_code' => 'PAY-RETENTION-AUDIT-001',
            'amount' => 5000,
            'payment_date' => Carbon::now()->subMonths(6),
            'payment_method' => 'cash',
            'payment_type' => 'emi',
            'status' => 'success',
            'created_by' => $this->staff->id,
        ]);

        // 3. Create an old ephemeral device event
        $event = DeviceEvent::create([
            'company_id' => $this->company->id,
            'device_id' => $this->device->id,
            'event_type' => 'heartbeat_ping',
            'severity' => 'info',
            'event_time' => Carbon::now()->subDays(120),
            'created_at' => Carbon::now()->subDays(120),
        ]);

        // 4. Run data retention command
        Artisan::call('retention:apply');

        // 5. Verify financial payment and consent records MUST NEVER be deleted
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('device_consents', ['id' => $consent->id]);
    }

    /**
     * Section 13: Multi-tenant isolation and unauthorized access rejection.
     */
    public function test_api_security_tenant_isolation(): void
    {
        // 1. Unauthenticated access rejected with 401
        $this->getJson("/api/v1/devices/{$this->device->id}")->assertUnauthorized();

        // 2. Other company staff cannot view or access this company's device (404)
        $otherCompany = Company::factory()->create();
        $otherStaff = User::factory()->create(['company_id' => $otherCompany->id]);
        $otherStaff->assignRole('staff');

        Sanctum::actingAs($otherStaff);
        $this->getJson("/api/v1/devices/{$this->device->id}")->assertNotFound();

        // Other company staff cannot view this company's EMI account
        $this->getJson("/api/v1/emi-accounts/{$this->emiAccount->id}")->assertNotFound();
    }

    /**
     * Requirements 1, 2, 3, 11, 12: Permanent identity & history retained, credentials retired, row not deleted.
     */
    public function test_permanent_device_identity_and_history_retained_after_complete_payment(): void
    {
        Sanctum::actingAs($this->staff);

        $this->device->update([
            'imei1' => '358912345678901',
            'imei2' => '358912345678902',
            'serial_number' => 'SN-GALAXY-M34-999',
            'manufacturer' => 'Samsung Electronics',
            'enrollment_status' => 'enrolled',
            'management_status' => 'MANAGED',
            'connection_status' => 'ONLINE',
            'device_lock_status' => 'UNLOCKED',
        ]);

        // Complete full payment
        $response = $this->postJson("/api/emi/{$this->emiAccount->id}/payment", [
            'amount' => 20000,
            'payment_date' => Carbon::now()->toDateString(),
            'payment_method' => 'cash',
            'status' => 'verified',
            'transaction_reference' => 'TXN-PERMANENT-IDENTITY-TEST',
        ]);

        $response->assertCreated();

        $freshDevice = $this->device->fresh();
        $freshAccount = $this->emiAccount->fresh();

        // 1. Status transitions
        $this->assertEquals('RELEASED', $freshDevice->management_status);
        $this->assertEquals('UNLOCKED', $freshDevice->device_lock_status);
        $this->assertEquals('PAID', $freshAccount->emi_status);
        $this->assertEquals(0, (float) $freshAccount->outstanding_amount);
        $this->assertNotNull($freshDevice->released_at);

        // 2. Permanent device identity strictly preserved
        $this->assertEquals('358912345678901', $freshDevice->imei);
        $this->assertEquals('358912345678902', $freshDevice->imei2);
        $this->assertEquals('SN-GALAXY-M34-999', $freshDevice->serial_number);
        $this->assertEquals('Samsung Electronics', $freshDevice->manufacturer);
        $this->assertEquals('Galaxy M34', $freshDevice->model);
        $this->assertEquals($this->customer->id, $freshDevice->customer_id);
        $this->assertEquals($this->emiAccount->id, $freshDevice->emi_account_id);

        // 3. Device row MUST NOT be deleted
        $this->assertNull($freshDevice->deleted_at);
        $this->assertDatabaseHas('devices', [
            'id' => $this->device->id,
            'imei1' => '358912345678901',
            'serial_number' => 'SN-GALAXY-M34-999',
            'management_status' => 'RELEASED',
        ]);

        // 4. Historical records preserved
        $this->assertDatabaseHas('payments', [
            'emi_account_id' => $this->emiAccount->id,
            'status' => 'verified',
        ]);
        $this->assertDatabaseHas('device_releases', [
            'device_id' => $this->device->id,
            'customer_id' => $this->customer->id,
        ]);
    }

    /**
     * Requirement 4 & 5: Separate fields for connection_status, emi_status, management_status, device_lock_status.
     */
    public function test_separate_lifecycle_statuses_maintained(): void
    {
        $this->device->update([
            'connection_status' => 'ONLINE',
            'management_status' => 'MANAGED',
            'device_lock_status' => 'UNLOCKED',
        ]);

        $this->emiAccount->update(['emi_status' => 'ACTIVE']);

        $fresh = $this->device->fresh();
        $this->assertEquals('ONLINE', $fresh->connection_status);
        $this->assertEquals('MANAGED', $fresh->management_status);
        $this->assertEquals('UNLOCKED', $fresh->device_lock_status);
        $this->assertEquals('ACTIVE', $this->emiAccount->fresh()->emi_status);

        // Offline check-in timeout alters connection_status without affecting management_status or lock_status
        $fresh->update(['connection_status' => 'OFFLINE']);
        $this->assertEquals('OFFLINE', $fresh->fresh()->connection_status);
        $this->assertEquals('MANAGED', $fresh->fresh()->management_status);
        $this->assertEquals('UNLOCKED', $fresh->fresh()->device_lock_status);
    }

    /**
     * Requirement 6 & 7: 3 distinct EMI installments overdue trigger LOCK_PENDING and LOCK command with shop info.
     */
    public function test_three_distinct_overdue_installments_trigger_lock(): void
    {
        $this->device->update([
            'enrollment_status' => 'enrolled',
            'management_status' => 'MANAGED',
            'management_mode' => 'device_owner',
            'device_lock_status' => 'UNLOCKED',
            'capabilities' => ['can_enforce_lock_task' => true, 'can_show_warning' => true],
        ]);
        $this->customer->update(['consent_given' => true]);

        // Set 3 schedules overdue past grace period
        $schedules = $this->emiAccount->schedules()->take(3)->get();
        foreach ($schedules as $s) {
            $s->update([
                'due_date' => Carbon::now()->subDays(20),
                'grace_until' => Carbon::now()->subDays(15),
                'status' => 'overdue',
                'overdue_amount' => 5000,
                'outstanding_amount' => 5000,
            ]);
        }

        $this->emiAccount->update([
            'status' => 'overdue',
            'next_due_date' => Carbon::now()->subDays(20),
            'auto_lock_enabled' => true,
        ]);

        app(\App\Services\EmiOverdueService::class)->recalculate($this->emiAccount->fresh());

        $freshAccount = $this->emiAccount->fresh();
        $this->assertEquals('LOCKED', $freshAccount->emi_status);

        // Command was queued
        $command = $this->device->commands()->where('command_type', 'full_lock')->latest('id')->first();
        $this->assertNotNull($command);
        $this->assertEquals('full_lock', $command->command_type);
        $this->assertArrayHasKey('shop_name', $command->payload);
        $this->assertArrayHasKey('support_phone', $command->payload);
        $this->assertArrayHasKey('outstanding_amount', $command->payload);
        $this->assertArrayHasKey('lock_reason', $command->payload);
        $this->assertEquals('Lock for your device and go to shop', $command->payload['lock_reason']);
    }

    /**
     * Requirement 11 & Acceptance Criteria: Device cannot be locked again after EMI is completed / PAID.
     */
    public function test_device_cannot_be_locked_after_emi_paid(): void
    {
        $this->device->update([
            'enrollment_status' => 'released',
            'management_status' => 'RELEASED',
            'released_at' => now(),
        ]);
        $this->emiAccount->update([
            'status' => 'completed',
            'emi_status' => 'PAID',
            'outstanding_amount' => 0,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\DeviceCommandService::class)->queue($this->device->fresh(), 'full_lock', [
            'message' => 'Attempted lock after release',
        ]);
    }
}

