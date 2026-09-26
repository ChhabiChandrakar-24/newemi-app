<?php

namespace Tests\Unit;

use App\Support\Money;
use Tests\TestCase;

class PaymentAllocationRuleTest extends TestCase
{
    public function test_cash_and_discount_reconcile_in_integer_paise(): void
    {
        $outstanding = Money::toPaise('9000.00');
        $discount = Money::toPaise('1000.00');
        $cash = $outstanding - $discount;

        $this->assertSame(800000, $cash);
        $this->assertSame($outstanding, $cash + $discount);
        $this->assertSame('8000.00', Money::fromPaise($cash));
    }
}
