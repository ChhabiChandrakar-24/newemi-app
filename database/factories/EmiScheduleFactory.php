<?php

namespace Database\Factories;

use App\Models\EmiAccount;
use App\Models\EmiSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmiSchedule> */
class EmiScheduleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'emi_account_id' => EmiAccount::factory(),
            'installment_number' => 1,
            'due_date' => today()->addMonth(),
            'opening_balance' => '1000.00',
            'principal_due' => '1000.00',
            'interest_due' => '0.00',
            'installment_amount' => '1000.00',
            'paid_amount' => '0.00',
            'outstanding_amount' => '1000.00',
            'overdue_amount' => '0.00',
            'status' => 'pending',
            'grace_until' => today()->addMonth()->addDays(3),
        ];
    }
}
