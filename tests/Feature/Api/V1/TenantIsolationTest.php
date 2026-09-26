<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Device;
use App\Models\EmiAccount;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $alpha;

    private Company $beta;

    private User $alphaAdmin;

    private User $betaAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->alpha = Company::factory()->create(['name' => 'Alpha', 'company_code' => 'CMP-ALPHA']);
        $this->beta = Company::factory()->create(['name' => 'Beta', 'company_code' => 'CMP-BETA']);
        $this->alphaAdmin = User::factory()->create(['company_id' => $this->alpha->id]);
        $this->betaAdmin = User::factory()->create(['company_id' => $this->beta->id]);
        $this->alphaAdmin->assignRole('admin');
        $this->betaAdmin->assignRole('admin');
    }

    public function test_company_a_cannot_read_or_mutate_company_b_resources_by_direct_id(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->beta->id, 'created_by' => $this->betaAdmin->id]);
        $account = EmiAccount::factory()->create(['company_id' => $this->beta->id, 'customer_id' => $customer->id, 'created_by' => $this->betaAdmin->id]);
        $payment = Payment::factory()->create(['company_id' => $this->beta->id, 'customer_id' => $customer->id, 'emi_account_id' => $account->id, 'created_by' => $this->betaAdmin->id]);
        $device = Device::factory()->create(['company_id' => $this->beta->id, 'customer_id' => $customer->id, 'emi_account_id' => $account->id, 'created_by' => $this->betaAdmin->id]);
        Sanctum::actingAs($this->alphaAdmin);

        $this->getJson("/api/v1/customers/{$customer->id}")->assertNotFound();
        $this->putJson("/api/v1/customers/{$customer->id}", [])->assertNotFound();
        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertNotFound();
        $this->getJson("/api/v1/emi-accounts/{$account->id}")->assertNotFound();
        $this->getJson("/api/v1/payments/{$payment->id}")->assertNotFound();
        $this->getJson("/api/v1/devices/{$device->id}")->assertNotFound();
        $this->postJson("/api/v1/devices/{$device->id}/commands/warning", ['message' => 'x'])->assertNotFound();
        $this->getJson("/api/v1/devices/{$device->id}/location")->assertNotFound();
    }

    public function test_cross_company_relationship_creation_is_rejected(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->beta->id, 'created_by' => $this->betaAdmin->id]);
        $account = EmiAccount::factory()->create(['company_id' => $this->beta->id, 'customer_id' => $customer->id, 'created_by' => $this->betaAdmin->id]);
        Sanctum::actingAs($this->alphaAdmin);

        $this->postJson('/api/v1/payments', [
            'emi_account_id' => $account->id, 'payment_date' => today()->toDateString(), 'amount' => 100,
            'payment_method' => 'cash', 'payment_type' => 'emi', 'status' => 'pending',
        ])->assertNotFound();
        $this->postJson('/api/v1/devices', ['customer_id' => $customer->id, 'display_name' => 'Forbidden device'])
            ->assertNotFound();
    }

    public function test_lists_settings_audit_and_users_are_company_scoped(): void
    {
        Customer::factory()->create(['company_id' => $this->alpha->id, 'full_name' => 'Alpha Customer', 'created_by' => $this->alphaAdmin->id]);
        Customer::factory()->create(['company_id' => $this->beta->id, 'full_name' => 'Beta Customer', 'created_by' => $this->betaAdmin->id]);
        SystemSetting::withoutGlobalScopes()->create(['company_id' => $this->beta->id, 'category' => 'company', 'key' => 'name', 'value' => encrypt('Beta')]);
        AuditLog::withoutGlobalScopes()->create(['company_id' => $this->beta->id, 'action' => 'beta.secret', 'entity_type' => 'test', 'entity_id' => '1']);
        Sanctum::actingAs($this->alphaAdmin);

        $this->getJson('/api/v1/customers')->assertOk()->assertJsonMissing(['full_name' => 'Beta Customer']);
        $this->getJson('/api/v1/users')->assertOk()->assertJsonMissing(['email' => $this->betaAdmin->email]);
        $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonMissing(['action' => 'beta.secret']);
        $this->getJson('/api/v1/settings/company')->assertOk()->assertJsonMissing(['name' => 'Beta']);
        $this->getJson('/api/v1/customers?company_id='.$this->beta->id)->assertOk()->assertJsonMissing(['full_name' => 'Beta Customer']);
    }

    public function test_platform_admin_can_manage_companies_but_company_admin_cannot(): void
    {
        Sanctum::actingAs($this->alphaAdmin);
        $this->getJson('/api/v1/platform/companies')->assertForbidden();

        $platform = User::factory()->create(['company_id' => null, 'is_platform_admin' => true]);
        $platform->assignRole('super-admin');
        Sanctum::actingAs($platform);
        $this->getJson('/api/v1/platform/companies')->assertOk();
        $this->getJson('/api/v1/customers')->assertForbidden();
    }

    public function test_platform_company_onboarding_update_suspend_archive_and_restore(): void
    {
        $platform = User::factory()->create(['company_id' => null, 'is_platform_admin' => true]);
        $platform->assignRole('super-admin');
        Sanctum::actingAs($platform);

        $created = $this->postJson('/api/v1/platform/companies', [
            'name' => 'Gamma Finance', 'email' => 'contact@gamma.example', 'plan' => 'growth',
            'max_users' => 10, 'max_devices' => 100, 'subscription_status' => 'trial',
            'owner' => ['name' => 'Gamma Owner', 'email' => 'owner@gamma.example', 'password' => 'GammaOwner@123'],
        ])->assertCreated()->assertJsonPath('company.name', 'Gamma Finance');
        $id = $created->json('company.id');
        $this->assertDatabaseHas('users', ['company_id' => $id, 'email' => 'owner@gamma.example']);
        $this->putJson("/api/v1/platform/companies/{$id}", ['name' => 'Gamma Finance Updated', 'max_users' => 12])
            ->assertOk()->assertJsonPath('company.max_users', 12);
        $this->patchJson("/api/v1/platform/companies/{$id}/status", ['status' => 'suspended', 'reason' => 'UAT'])
            ->assertOk()->assertJsonPath('company.status', 'suspended');
        $this->patchJson("/api/v1/platform/companies/{$id}/status", ['status' => 'closed', 'reason' => 'UAT close'])->assertOk();
        $this->deleteJson("/api/v1/platform/companies/{$id}", ['reason' => 'UAT archive'])->assertOk();
        $this->postJson("/api/v1/platform/companies/{$id}/restore")->assertOk();
    }
}
