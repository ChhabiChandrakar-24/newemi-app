<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use App\Models\EmiAccount;
use App\Models\Payment;
use App\Models\User;
use App\Services\EmiAccountService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_unauthenticated_is_rejected_and_auditor_is_view_only(): void
    {
        $this->getJson('/api/v1/payments')->assertUnauthorized();
        $account = $this->account();
        Sanctum::actingAs($this->userWithRole('auditor'));

        $this->getJson('/api/v1/payments')->assertOk();
        $this->postJson('/api/v1/payments', $this->paymentPayload($account))->assertForbidden();
    }

    public function test_pending_payment_does_not_change_balance_and_can_be_cancelled(): void
    {
        $account = $this->account();
        $staff = $this->userWithRole('staff');
        Sanctum::actingAs($staff);

        $response = $this->postJson('/api/v1/payments', $this->paymentPayload($account, [
            'amount' => '1000.00',
            'status' => 'pending',
        ]))->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.receipt_number', null);

        $this->assertSame('9000.00', $account->fresh()->outstanding_amount);
        $this->assertDatabaseCount('payment_allocations', 0);
        $paymentId = $response->json('data.id');
        Sanctum::actingAs($this->userWithRole('manager'));
        $this->patchJson("/api/v1/payments/{$paymentId}/cancel", ['remarks' => 'Duplicate cash entry'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.created', 'entity_id' => (string) $paymentId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.cancelled', 'entity_id' => (string) $paymentId]);
    }

    public function test_verified_payment_allocates_oldest_priority_and_updates_account(): void
    {
        $account = $this->account();
        $schedules = $account->schedules()->orderBy('installment_number')->get();
        $schedules[0]->update(['status' => 'pending']);
        $schedules[1]->update(['status' => 'overdue', 'overdue_amount' => '3000.00']);
        $schedules[2]->update(['status' => 'due']);
        $manager = $this->userWithRole('manager');
        Sanctum::actingAs($manager);

        $response = $this->postJson('/api/v1/payments', $this->paymentPayload($account, [
            'amount' => '4500.00',
            'status' => 'verified',
        ]))->assertCreated()
            ->assertJsonPath('data.status', 'verified');

        $paymentId = $response->json('data.id');
        $allocations = Payment::find($paymentId)->allocations()->orderBy('id')->get();
        $this->assertSame($schedules[1]->id, $allocations[0]->emi_schedule_id);
        $this->assertSame('3000.00', $allocations[0]->allocated_amount);
        $this->assertSame($schedules[2]->id, $allocations[1]->emi_schedule_id);
        $this->assertSame('1500.00', $allocations[1]->allocated_amount);
        $this->assertSame(450000, $allocations->sum(fn ($allocation): int => Money::toPaise($allocation->allocated_amount)));
        $this->assertSame('4500.00', $account->fresh()->total_paid);
        $this->assertSame('4500.00', $account->fresh()->outstanding_amount);
        $this->assertMatchesRegularExpression('/^RCT-\d{4}-\d{6,}$/', $response->json('data.receipt_number'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.verified', 'entity_id' => (string) $paymentId]);
    }

    public function test_partial_and_exact_payments_update_schedule_statuses(): void
    {
        $account = $this->account();
        $manager = $this->userWithRole('manager');
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/payments', $this->paymentPayload($account, ['amount' => '1000.00', 'status' => 'verified']))->assertCreated();
        $this->assertDatabaseHas('emi_schedules', [
            'emi_account_id' => $account->id,
            'installment_number' => 1,
            'paid_amount' => 1000,
            'outstanding_amount' => 2000,
            'status' => 'partially_paid',
        ]);

        $this->postJson('/api/v1/payments', $this->paymentPayload($account, ['amount' => '2000.00', 'status' => 'verified']))->assertCreated();
        $this->assertDatabaseHas('emi_schedules', [
            'emi_account_id' => $account->id,
            'installment_number' => 1,
            'paid_amount' => 3000,
            'outstanding_amount' => 0,
            'status' => 'paid',
        ]);
    }

    public function test_invalid_account_amount_and_overpayment_are_rejected(): void
    {
        $manager = $this->userWithRole('manager');
        Sanctum::actingAs($manager);
        $account = $this->account();

        $this->postJson('/api/v1/payments', $this->paymentPayload($account, ['amount' => '0.00']))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson('/api/v1/payments', $this->paymentPayload($account, ['amount' => '9000.01']))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $cancelled = $this->account();
        $cancelled->update(['status' => 'cancelled']);
        $this->postJson('/api/v1/payments', $this->paymentPayload($cancelled))
            ->assertUnprocessable()->assertJsonValidationErrors('emi_account_id');

        $payload = $this->paymentPayload($account);
        $payload['emi_account_id'] = 999999;
        $this->postJson('/api/v1/payments', $payload)->assertUnprocessable()->assertJsonValidationErrors('emi_account_id');
    }

    public function test_pending_verification_allocates_and_cannot_be_repeated(): void
    {
        $account = $this->account();
        $staff = $this->userWithRole('staff');
        Sanctum::actingAs($staff);
        $paymentId = $this->postJson('/api/v1/payments', $this->paymentPayload($account))->json('data.id');

        Sanctum::actingAs($this->userWithRole('manager'));
        $this->patchJson("/api/v1/payments/{$paymentId}/verify")->assertOk()->assertJsonPath('data.status', 'verified');
        $this->patchJson("/api/v1/payments/{$paymentId}/verify")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_idempotency_key_prevents_duplicate_submission(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->userWithRole('staff'));
        $payload = $this->paymentPayload($account);

        $first = $this->withHeader('Idempotency-Key', 'payment-request-123')->postJson('/api/v1/payments', $payload)->assertCreated();
        $second = $this->withHeader('Idempotency-Key', 'payment-request-123')->postJson('/api/v1/payments', $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_staff_cannot_reverse_and_verified_payment_reversal_restores_balance(): void
    {
        $account = $this->account();
        Sanctum::actingAs($this->userWithRole('manager'));
        $paymentId = $this->postJson('/api/v1/payments', $this->paymentPayload($account, [
            'amount' => '9000.00',
            'status' => 'verified',
        ]))->json('data.id');
        $this->assertSame('completed', $account->fresh()->status);

        Sanctum::actingAs($this->userWithRole('staff'));
        $this->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'Incorrect collection record'])->assertForbidden();

        Sanctum::actingAs($this->userWithRole('manager'));
        $this->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'Incorrect collection record'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');
        $account->refresh();
        $this->assertSame('9000.00', $account->outstanding_amount);
        $this->assertSame('0.00', $account->total_paid);
        $this->assertNotSame('closed', $account->status);
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'reversed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.reversed', 'entity_id' => (string) $paymentId]);
        $this->postJson("/api/v1/payments/{$paymentId}/reverse", ['reason' => 'Attempt reversal again'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment');
    }

    public function test_receipt_list_search_and_related_histories_work(): void
    {
        $account = $this->account();
        $manager = $this->userWithRole('manager');
        Sanctum::actingAs($manager);
        $payment = $this->postJson('/api/v1/payments', $this->paymentPayload($account, [
            'status' => 'verified',
            'transaction_reference' => 'UPI-SEARCH-001',
        ]))->assertCreated();
        $paymentId = $payment->json('data.id');

        $this->getJson("/api/v1/payments/{$paymentId}")->assertOk();
        $this->getJson("/api/v1/payments/{$paymentId}/receipt")
            ->assertOk()
            ->assertJsonPath('data.payment_code', $payment->json('data.payment_code'))
            ->assertJsonPath('data.remaining_account_balance', '8000.00');
        $this->getJson('/api/v1/payments?search=UPI-SEARCH-001&status=verified&sort=amount&direction=desc')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/emi-accounts/{$account->id}/payments")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/customers/{$account->customer_id}/payments")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/emi-accounts/{$account->id}/summary")
            ->assertJsonPath('data.payments.verified_count', 1)
            ->assertJsonPath('data.payments.total_paid', '1000.00');
    }

    private function account(): EmiAccount
    {
        $actor = User::factory()->create();
        $customer = Customer::factory()->create(['status' => 'active']);

        return app(EmiAccountService::class)->create([
            'customer_id' => $customer->id,
            'invoice_number' => fake()->unique()->bothify('INV-####-????'),
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

    private function paymentPayload(EmiAccount $account, array $overrides = []): array
    {
        return array_merge([
            'emi_account_id' => $account->id,
            'payment_date' => today()->toDateString(),
            'amount' => '1000.00',
            'payment_method' => 'cash',
            'transaction_reference' => null,
            'external_reference' => null,
            'payment_type' => 'emi',
            'status' => 'pending',
            'notes' => null,
        ], $overrides);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
