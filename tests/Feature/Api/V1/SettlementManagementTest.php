<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use App\Models\EmiAccount;
use App\Models\User;
use App\Services\EmiAccountService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettlementManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_preview_and_settlement_without_discount_complete_but_do_not_close_account(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->userWithRole('manager'));

        $this->postJson("/api/v1/emi-accounts/{$account->id}/settlement-preview", ['discount_amount' => '0.00'])
            ->assertOk()
            ->assertJsonPath('data.current_outstanding_amount', '9000.00')
            ->assertJsonPath('data.final_settlement_payable', '9000.00')
            ->assertJsonPath('data.remaining_installment_count', 3);

        $payment = $this->postJson("/api/v1/emi-accounts/{$account->id}/settle", $this->settlementPayload([
            'settlement_amount' => '9000.00',
        ]))->assertCreated()
            ->assertJsonPath('data.payment_type', 'settlement')
            ->assertJsonPath('data.status', 'verified');

        $account->refresh();
        $this->assertSame('0.00', $account->outstanding_amount);
        $this->assertSame('completed', $account->status);
        $this->assertNotSame('closed', $account->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settlement.completed', 'entity_id' => (string) $account->id]);
        $this->assertNotNull($payment->json('data.receipt_number'));
    }

    public function test_discount_requires_permission_and_strict_arithmetic(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->userWithRole('manager'));

        $this->postJson("/api/v1/emi-accounts/{$account->id}/settlement-preview", ['discount_amount' => '1000.00'])
            ->assertForbidden();
        $this->postJson("/api/v1/emi-accounts/{$account->id}/settle", $this->settlementPayload([
            'settlement_amount' => '8000.00',
            'discount_amount' => '1000.00',
        ]))->assertForbidden();

        Sanctum::actingAs($this->userWithRole('admin'));
        $this->postJson("/api/v1/emi-accounts/{$account->id}/settle", $this->settlementPayload([
            'settlement_amount' => '7999.99',
            'discount_amount' => '1000.00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('settlement_amount');
        $this->postJson("/api/v1/emi-accounts/{$account->id}/settle", $this->settlementPayload([
            'settlement_amount' => '4000.00',
            'discount_amount' => '5000.00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('discount_amount');
    }

    public function test_authorized_discount_is_separate_adjustment_and_reconciles_exactly(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->userWithRole('admin'));

        $this->postJson("/api/v1/emi-accounts/{$account->id}/settlement-preview", ['discount_amount' => '1000.00'])
            ->assertOk()
            ->assertJsonPath('data.final_settlement_payable', '8000.00');

        $payment = $this->postJson("/api/v1/emi-accounts/{$account->id}/settle", $this->settlementPayload([
            'settlement_amount' => '8000.00',
            'discount_amount' => '1000.00',
        ]))->assertCreated();
        $paymentId = $payment->json('data.id');

        $cash = Money::toPaise((string) $account->payments()->find($paymentId)->allocations()->where('allocation_type', 'installment')->sum('allocated_amount'));
        $adjustment = Money::toPaise((string) $account->payments()->find($paymentId)->allocations()->where('allocation_type', 'adjustment')->sum('allocated_amount'));
        $this->assertSame(800000, $cash);
        $this->assertSame(100000, $adjustment);
        $this->assertSame(900000, $cash + $adjustment);
        $account->refresh();
        $this->assertSame('8000.00', $account->total_paid);
        $this->assertSame('0.00', $account->outstanding_amount);
        $this->assertSame('completed', $account->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settlement.discount_applied', 'entity_id' => (string) $paymentId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settlement.completed', 'entity_id' => (string) $account->id]);
    }

    public function test_reconcile_command_is_report_only_and_clean_ledger_passes(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->userWithRole('manager'));
        $this->postJson("/api/v1/emi-accounts/{$account->id}/settle", $this->settlementPayload([
            'settlement_amount' => '9000.00',
        ]))->assertCreated();

        $before = $account->fresh()->updated_at->toISOString();
        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('found 0 inconsistency(ies). No data was modified.')
            ->assertSuccessful();
        $this->assertSame($before, $account->fresh()->updated_at->toISOString());
    }

    public function test_discounted_settlement_reversal_restores_full_outstanding_and_keeps_ledger(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->userWithRole('admin'));
        $paymentId = $this->postJson("/api/v1/emi-accounts/{$account->id}/settle", $this->settlementPayload([
            'settlement_amount' => '8000.00',
            'discount_amount' => '1000.00',
        ]))->assertCreated()->json('data.id');
        $allocationCount = $account->payments()->find($paymentId)->allocations()->count();

        $this->postJson("/api/v1/payments/{$paymentId}/reverse", [
            'reason' => 'Settlement approval withdrawn',
        ])->assertOk()->assertJsonPath('data.status', 'reversed');

        $account->refresh();
        $this->assertSame('9000.00', $account->outstanding_amount);
        $this->assertSame('0.00', $account->total_paid);
        $this->assertSame('active', $account->status);
        $this->assertSame($allocationCount, $account->payments()->find($paymentId)->allocations()->count());
    }

    private function account(): EmiAccount
    {
        $actor = User::factory()->create();
        $customer = Customer::factory()->create(['status' => 'active']);

        return app(EmiAccountService::class)->create([
            'customer_id' => $customer->id,
            'invoice_number' => fake()->unique()->bothify('INV-SET-####'),
            'financed_amount' => '9000.00',
            'down_payment' => '0.00',
            'total_installments' => 3,
            'emi_start_date' => today()->addMonth()->toDateString(),
            'due_day' => today()->addMonth()->day,
            'grace_period_days' => 2,
            'interest_amount' => '0.00',
            'processing_fee' => '0.00',
            'other_charges' => '0.00',
            'status' => 'active',
            'auto_lock_enabled' => false,
        ], $actor);
    }

    private function settlementPayload(array $overrides = []): array
    {
        return array_merge([
            'settlement_amount' => '9000.00',
            'discount_amount' => '0.00',
            'payment_method' => 'bank_transfer',
            'payment_date' => today()->toDateString(),
            'transaction_reference' => 'SETTLEMENT-REF-001',
            'remarks' => 'Approved final EMI settlement',
        ], $overrides);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
