<?php

namespace Tests\Unit;

use App\Models\EmiAccount;
use App\Services\EmiScheduleGenerator;
use App\Support\Money;
use Tests\TestCase;

class EmiScheduleGeneratorTest extends TestCase
{
    public function test_money_conversion_preserves_paise_exactly(): void
    {
        $this->assertSame(123456, Money::toPaise('1234.56'));
        $this->assertSame('1234.56', Money::fromPaise(123456));
    }

    public function test_generator_allocates_remainder_and_handles_month_end(): void
    {
        $account = new EmiAccount([
            'principal_amount' => '10000.00',
            'interest_amount' => '0.00',
            'total_payable' => '10000.00',
            'total_installments' => 3,
            'emi_start_date' => '2026-01-31',
            'grace_period_days' => 2,
        ]);

        $rows = (new EmiScheduleGenerator)->build($account);

        $this->assertSame(['2026-01-31 00:00:00', '2026-02-28 00:00:00', '2026-03-31 00:00:00'], $rows->pluck('due_date')->all());
        $this->assertSame(['3333.33', '3333.33', '3333.34'], $rows->pluck('installment_amount')->all());
        $this->assertSame(1000000, $rows->sum(fn (array $row): int => Money::toPaise($row['installment_amount'])));
    }
}
