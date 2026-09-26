<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_unauthenticated_customer_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
        $this->postJson('/api/v1/customers', [])->assertUnauthorized();
    }

    public function test_user_without_view_permission_receives_forbidden(): void
    {
        $role = Role::create(['name' => 'no-customer-access', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/customers')->assertForbidden();
    }

    public function test_authorized_user_can_view_customer_and_real_summary(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($this->userWithRole('auditor'));

        $this->getJson('/api/v1/customers')->assertOk();
        $this->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id)
            ->assertJsonMissingPath('data.identity_number');
        $this->getJson("/api/v1/customers/{$customer->id}/summary")
            ->assertOk()
            ->assertJsonPath('data.statistics.audit_events_count', 0)
            ->assertJsonMissingPath('data.statistics.emi_accounts_count');
    }

    public function test_auditor_can_view_but_cannot_mutate_customers(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($this->userWithRole('auditor'));

        $this->getJson('/api/v1/customers')->assertOk();
        $this->postJson('/api/v1/customers', $this->payload())->assertForbidden();
        $this->putJson("/api/v1/customers/{$customer->id}", $this->payload())->assertForbidden();
        $this->patchJson("/api/v1/customers/{$customer->id}/status", ['status' => 'closed'])->assertForbidden();
        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertForbidden();
    }

    public function test_valid_customer_is_created_with_generated_code_and_explicit_consent_default(): void
    {
        $actor = $this->userWithRole('staff');
        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/customers', $this->payload([
            'mobile_number' => '98765 43210',
        ]))->assertCreated()
            ->assertJsonPath('data.mobile_number', '+919876543210')
            ->assertJsonPath('data.consent.given', false)
            ->assertJsonPath('data.consent.given_at', null)
            ->assertJsonMissingPath('data.identity_number');

        $this->assertMatchesRegularExpression('/^CUS-\d{6,}$/', $response->json('data.customer_code'));
        $this->assertDatabaseHas('customers', [
            'id' => $response->json('data.id'),
            'created_by' => $actor->id,
            'consent_given' => false,
        ]);
    }

    public function test_invalid_mobile_and_email_are_rejected(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->postJson('/api/v1/customers', $this->payload(['mobile_number' => '12345']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mobile_number');
        $this->postJson('/api/v1/customers', $this->payload(['email' => 'not-an-email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_list_supports_pagination_search_and_filters(): void
    {
        $creator = User::factory()->create();
        Customer::factory()->create([
            'full_name' => 'Searchable Meera',
            'mobile_number' => '+919811111111',
            'status' => 'inactive',
            'consent_given' => true,
            'consent_given_at' => now(),
            'city' => 'Jaipur',
            'state' => 'Rajasthan',
            'created_by' => $creator->id,
        ]);
        Customer::factory()->count(2)->create(['created_by' => $creator->id]);
        Sanctum::actingAs($this->userWithRole('auditor'));

        $this->getJson('/api/v1/customers?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/customers?search=Searchable%20Meera')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/customers?search=9811111111')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/customers?status=inactive&consent_given=1&city=Jaipur&state=Rajasthan')
            ->assertJsonCount(1, 'data');
    }

    public function test_customer_update_grants_and_withdraws_consent_without_erasing_history(): void
    {
        $actor = $this->userWithRole('staff');
        $customer = Customer::factory()->create(['created_by' => $actor->id]);
        Sanctum::actingAs($actor);

        $grant = $this->putJson("/api/v1/customers/{$customer->id}", $this->payload([
            'full_name' => 'Updated Customer',
            'status' => 'inactive',
            'consent_given' => true,
        ]))->assertOk()
            ->assertJsonPath('data.full_name', 'Updated Customer')
            ->assertJsonPath('data.consent.given', true);

        $grantedAt = $grant->json('data.consent.given_at');
        $this->assertNotNull($grantedAt);

        $this->putJson("/api/v1/customers/{$customer->id}", $this->payload([
            'full_name' => 'Updated Customer',
            'consent_given' => false,
        ]))->assertOk()
            ->assertJsonPath('data.consent.given', false)
            ->assertJsonPath('data.consent.given_at', $grantedAt);

        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.consent_granted', 'entity_id' => (string) $customer->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.consent_withdrawn', 'entity_id' => (string) $customer->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.status_changed', 'entity_id' => (string) $customer->id]);
    }

    public function test_customer_status_change_and_soft_delete_work(): void
    {
        $admin = $this->userWithRole('admin');
        $customer = Customer::factory()->create(['created_by' => $admin->id]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/customers/{$customer->id}/status", ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertNoContent();

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.status_changed', 'entity_id' => (string) $customer->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'customer.deleted', 'entity_id' => (string) $customer->id]);
    }

    public function test_staff_without_delete_permission_is_rejected(): void
    {
        $staff = $this->userWithRole('staff');
        $customer = Customer::factory()->create(['created_by' => $staff->id]);
        Sanctum::actingAs($staff);

        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertForbidden();
        $this->assertNotSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_customer_create_and_update_produce_privacy_minimized_audit_records(): void
    {
        $actor = $this->userWithRole('staff');
        Sanctum::actingAs($actor);

        $create = $this->postJson('/api/v1/customers', $this->payload([
            'identity_type' => 'Passport',
            'identity_number' => 'SECRET-1234',
        ]))->assertCreated();
        $customerId = $create->json('data.id');
        $this->putJson("/api/v1/customers/{$customerId}", $this->payload(['full_name' => 'Audited Customer']))->assertOk();

        $logs = AuditLog::query()->where('entity_id', (string) $customerId)->orderBy('id')->get();
        $this->assertSame(['customer.created', 'customer.updated'], $logs->pluck('action')->all());
        $this->assertSame($actor->id, $logs->first()->actor_user_id);
        $this->assertArrayNotHasKey('identity_number', $logs->first()->new_values);
        $this->assertNotNull($logs->first()->ip_address);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Aarav Sharma',
            'mobile_number' => '+919876543210',
            'alternate_mobile_number' => null,
            'email' => 'aarav@example.com',
            'address_line_1' => '42 Market Road',
            'address_line_2' => null,
            'city' => 'Jaipur',
            'state' => 'Rajasthan',
            'postal_code' => '302001',
            'country' => 'India',
            'identity_type' => null,
            'identity_number' => null,
            'date_of_birth' => '1990-05-15',
            'status' => 'active',
            'notes' => null,
            'consent_given' => false,
        ], $overrides);
    }
}
