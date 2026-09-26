<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use App\Models\EmiAccount;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmiAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_unauthenticated_and_unauthorized_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/emi-accounts')->assertUnauthorized();

        $role = Role::create(['name' => 'no-emi-access', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/emi-accounts')->assertForbidden();
    }

    public function test_auditor_can_view_but_cannot_create_or_update(): void
    {
        $account = EmiAccount::factory()->create();
        Sanctum::actingAs($this->userWithRole('auditor'));

        $this->getJson('/api/v1/emi-accounts')->assertOk();
        $this->getJson("/api/v1/emi-accounts/{$account->id}")->assertOk();
        $this->postJson('/api/v1/emi-accounts', $this->payload())->assertForbidden();
        $this->putJson("/api/v1/emi-accounts/{$account->id}", $this->payload())->assertForbidden();
    }

    public function test_authorized_user_creates_account_and_exact_rounded_schedule(): void
    {
        $actor = $this->userWithRole('staff');
        $customer = Customer::factory()->create(['status' => 'active', 'created_by' => $actor->id]);
        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/v1/emi-accounts', $this->payload([
            'customer_id' => $customer->id,
            'financed_amount' => '10000.00',
            'down_payment' => '0.00',
            'interest_amount' => '0.00',
            'processing_fee' => '0.00',
            'other_charges' => '0.00',
            'total_installments' => 3,
            'emi_start_date' => '2026-01-31',
            'due_day' => 31,
        ]))->assertCreated()
            ->assertJsonPath('data.total_payable', '10000.00')
            ->assertJsonPath('data.outstanding_amount', '10000.00');

        $accountId = $response->json('data.id');
        $this->assertMatchesRegularExpression('/^EMI-\d{6,}$/', $response->json('data.emi_account_code'));

        $schedule = $this->getJson("/api/v1/emi-accounts/{$accountId}/schedule")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.due_date', '2026-01-31')
            ->assertJsonPath('data.1.due_date', '2026-02-28')
            ->assertJsonPath('data.2.due_date', '2026-03-31')
            ->assertJsonPath('data.2.installment_amount', '3333.34');

        $totalPaise = collect($schedule->json('data'))->sum(fn (array $row): int => Money::toPaise($row['installment_amount']));
        $this->assertSame(1000000, $totalPaise);
        $this->assertDatabaseHas('audit_logs', ['action' => 'emi_account.created', 'entity_id' => (string) $accountId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'emi_schedule.generated', 'entity_id' => (string) $accountId]);
    }

    public function test_invalid_customer_and_financial_inputs_are_rejected(): void
    {
        Sanctum::actingAs($this->userWithRole('staff'));

        $this->postJson('/api/v1/emi-accounts', $this->payload(['customer_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');

        $closed = Customer::factory()->create(['status' => 'closed']);
        $this->postJson('/api/v1/emi-accounts', $this->payload(['customer_id' => $closed->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');

        $customer = Customer::factory()->create(['status' => 'active']);
        $this->postJson('/api/v1/emi-accounts', $this->payload([
            'customer_id' => $customer->id,
            'financed_amount' => '1000.00',
            'down_payment' => '1000.01',
        ]))->assertUnprocessable()->assertJsonValidationErrors('down_payment');

        $this->postJson('/api/v1/emi-accounts', $this->payload([
            'customer_id' => $customer->id,
            'total_payable' => '1.00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('total_payable');
    }

    public function test_list_search_filters_overdue_and_pagination_work(): void
    {
        $matchingCustomer = Customer::factory()->create(['full_name' => 'Search Finance Customer', 'mobile_number' => '+919899999999']);
        EmiAccount::factory()->create([
            'customer_id' => $matchingCustomer->id,
            'invoice_number' => 'INV-SEARCH-1',
            'status' => 'overdue',
            'overdue_amount' => '500.00',
        ]);
        EmiAccount::factory()->count(2)->create();
        Sanctum::actingAs($this->userWithRole('auditor'));

        $this->getJson('/api/v1/emi-accounts?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/emi-accounts?search=Search%20Finance')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/emi-accounts?search=9899999999')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/emi-accounts?status=overdue&overdue=1&invoice_number=INV-SEARCH-1')->assertJsonCount(1, 'data');
    }

    public function test_detail_summary_schedule_and_customer_history_return_real_data(): void
    {
        $actor = $this->userWithRole('staff');
        $customer = Customer::factory()->create(['status' => 'active']);
        Sanctum::actingAs($actor);
        $accountId = $this->postJson('/api/v1/emi-accounts', $this->payload(['customer_id' => $customer->id]))->json('data.id');

        $this->getJson("/api/v1/emi-accounts/{$accountId}")->assertOk()->assertJsonPath('data.customer.id', $customer->id);
        $this->getJson("/api/v1/emi-accounts/{$accountId}/summary")
            ->assertOk()
            ->assertJsonPath('data.installments.total', 3)
            ->assertJsonPath('data.installments.paid', 0)
            ->assertJsonPath('data.installments.pending', 3);
        $this->getJson("/api/v1/emi-accounts/{$accountId}/schedule")
            ->assertOk()
            ->assertJsonPath('data.0.installment_number', 1)
            ->assertJsonPath('data.2.installment_number', 3);
        $this->getJson("/api/v1/customers/{$customer->id}/emi-accounts")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_draft_financial_update_regenerates_schedule(): void
    {
        $actor = $this->userWithRole('staff');
        $customer = Customer::factory()->create(['status' => 'active']);
        Sanctum::actingAs($actor);
        $accountId = $this->postJson('/api/v1/emi-accounts', $this->payload(['customer_id' => $customer->id]))->json('data.id');

        $this->putJson("/api/v1/emi-accounts/{$accountId}", $this->payload([
            'customer_id' => $customer->id,
            'total_installments' => 4,
            'status' => null,
        ]))->assertOk()->assertJsonPath('data.total_installments', 4);

        $this->assertDatabaseCount('emi_schedules', 4);
        $this->assertDatabaseHas('audit_logs', ['action' => 'emi_schedule.regenerated', 'entity_id' => (string) $accountId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'emi_account.updated', 'entity_id' => (string) $accountId]);
    }

    public function test_active_account_blocks_financial_changes_but_allows_metadata_update(): void
    {
        $actor = $this->userWithRole('staff');
        $customer = Customer::factory()->create(['status' => 'active']);
        Sanctum::actingAs($actor);
        $accountId = $this->postJson('/api/v1/emi-accounts', $this->payload([
            'customer_id' => $customer->id,
            'status' => 'active',
        ]))->json('data.id');

        $this->putJson("/api/v1/emi-accounts/{$accountId}", $this->payload([
            'customer_id' => $customer->id,
            'status' => null,
            'financed_amount' => '12000.00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('account');

        $this->putJson("/api/v1/emi-accounts/{$accountId}", $this->payload([
            'customer_id' => $customer->id,
            'status' => null,
            'product_description' => 'Updated handset metadata',
        ]))->assertOk()->assertJsonPath('data.product_description', 'Updated handset metadata');
    }

    public function test_status_transition_is_validated_and_audited(): void
    {
        $manager = $this->userWithRole('manager');
        $account = EmiAccount::factory()->create(['status' => 'draft']);
        Sanctum::actingAs($manager);

        $this->patchJson("/api/v1/emi-accounts/{$account->id}/status", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->patchJson("/api/v1/emi-accounts/{$account->id}/status", ['status' => 'draft'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->patchJson("/api/v1/emi-accounts/{$account->id}/status", ['status' => 'completed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->patchJson("/api/v1/emi-accounts/{$account->id}/status", ['status' => 'cancelled'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'emi_account.status_changed', 'entity_id' => (string) $account->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'emi_account.cancelled', 'entity_id' => (string) $account->id]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_filter(array_merge([
            'customer_id' => Customer::factory()->create(['status' => 'active'])->id,
            'invoice_number' => 'INV-2026-001',
            'invoice_date' => '2026-01-01',
            'product_description' => 'Financed smartphone',
            'financed_amount' => '10000.00',
            'down_payment' => '1000.00',
            'total_installments' => 3,
            'emi_start_date' => '2026-01-31',
            'due_day' => 31,
            'grace_period_days' => 2,
            'interest_amount' => '300.00',
            'processing_fee' => '100.00',
            'other_charges' => '50.00',
            'status' => 'draft',
            'auto_lock_enabled' => false,
            'notes' => null,
        ], $overrides), fn ($value, string $key): bool => ! ($key === 'status' && $value === null), ARRAY_FILTER_USE_BOTH);
    }
}
