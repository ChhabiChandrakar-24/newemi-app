<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmiAccount;
use App\Models\User;
use App\Services\EmiAccountService;
use App\Services\EmiOverdueService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmiOverdueCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_overdue_service_respects_due_date_and_grace_period(): void
    {
        $account = $this->activeAccount();
        $service = app(EmiOverdueService::class);

        $service->recalculate($account, CarbonImmutable::parse('2026-01-10'));
        $this->assertDatabaseHas('emi_schedules', ['emi_account_id' => $account->id, 'installment_number' => 1, 'status' => 'due']);

        $service->recalculate($account, CarbonImmutable::parse('2026-01-12'));
        $this->assertDatabaseHas('emi_schedules', ['emi_account_id' => $account->id, 'installment_number' => 1, 'status' => 'due']);

        $service->recalculate($account, CarbonImmutable::parse('2026-01-13'));
        $account->refresh();
        $this->assertSame('overdue', $account->status);
        $this->assertSame(1, $account->overdue_installments);
        $this->assertSame('3000.00', $account->overdue_amount);
    }

    public function test_recalculation_command_is_idempotent(): void
    {
        $account = $this->activeAccount();

        $this->artisan('emi:recalculate-overdue', ['--date' => '2026-01-13'])
            ->expectsOutputToContain('Processed 1 account(s)')
            ->assertSuccessful();
        $first = $account->fresh()->only(['status', 'overdue_amount', 'overdue_installments', 'outstanding_amount']);

        $this->artisan('emi:recalculate-overdue', ['--date' => '2026-01-13'])
            ->expectsOutputToContain('changed 0 schedule row(s)')
            ->assertSuccessful();

        $this->assertSame($first, $account->fresh()->only(['status', 'overdue_amount', 'overdue_installments', 'outstanding_amount']));
        $this->assertDatabaseCount('emi_schedules', 2);
    }

    private function activeAccount(): EmiAccount
    {
        $actor = User::factory()->create();
        $customer = Customer::factory()->create(['status' => 'active']);

        return app(EmiAccountService::class)->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-OVERDUE',
            'financed_amount' => '6000.00',
            'down_payment' => '0.00',
            'total_installments' => 2,
            'emi_start_date' => '2026-01-10',
            'due_day' => 10,
            'grace_period_days' => 2,
            'interest_amount' => '0.00',
            'processing_fee' => '0.00',
            'other_charges' => '0.00',
            'status' => 'active',
            'auto_lock_enabled' => false,
        ], $actor);
    }
}
