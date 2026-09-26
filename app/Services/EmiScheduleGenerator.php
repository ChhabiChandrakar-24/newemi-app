<?php

namespace App\Services;

use App\Models\EmiAccount;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class EmiScheduleGenerator
{
    /** @return Collection<int, array<string, mixed>> */
    public function build(EmiAccount $account): Collection
    {
        $installments = $account->total_installments;
        $totalPaise = Money::toPaise($account->total_payable);
        $principalPaise = Money::toPaise($account->principal_amount);
        $interestPaise = Money::toPaise($account->interest_amount);
        $baseInstallment = intdiv($totalPaise, $installments);
        $basePrincipal = intdiv($principalPaise, $installments);
        $baseInterest = intdiv($interestPaise, $installments);
        $openingBalance = $totalPaise;
        $start = CarbonImmutable::parse($account->emi_start_date);

        return collect(range(1, $installments))->map(function (int $number) use (
            $account,
            $installments,
            $totalPaise,
            $principalPaise,
            $interestPaise,
            $baseInstallment,
            $basePrincipal,
            $baseInterest,
            &$openingBalance,
            $start,
        ): array {
            $isFinal = $number === $installments;
            $installment = $isFinal ? $totalPaise - ($baseInstallment * ($installments - 1)) : $baseInstallment;
            $principal = $isFinal ? $principalPaise - ($basePrincipal * ($installments - 1)) : $basePrincipal;
            $interest = $isFinal ? $interestPaise - ($baseInterest * ($installments - 1)) : $baseInterest;
            $frequency = $account->emi_frequency ?? 'monthly';
            if ($frequency === 'weekly') {
                $dueDate = $start->addWeeks($number - 1);
            } elseif ($frequency === 'daily') {
                $dueDate = $start->addDays($number - 1);
            } elseif ($frequency === 'hourly') {
                $dueDate = $start->addHours($number - 1);
            } elseif ($frequency === 'minutes') {
                $dueDate = $start->addMinutes($number - 1);
            } elseif ($frequency === 'custom_minutes') {
                $mins = max(1, (int) ($account->emi_frequency_days ?? 1));
                $dueDate = $start->addMinutes(($number - 1) * $mins);
            } elseif ($frequency === 'custom_hours') {
                $hrs = max(1, (int) ($account->emi_frequency_days ?? 1));
                $dueDate = $start->addHours(($number - 1) * $hrs);
            } elseif ($frequency === 'custom_days') {
                $days = max(1, (int) ($account->emi_frequency_days ?? 1));
                $dueDate = $start->addDays(($number - 1) * $days);
            } else {
                $dueDate = $start->addMonthsNoOverflow($number - 1);
            }
            $row = [
                'installment_number' => $number,
                'due_date' => $dueDate->toDateTimeString(),
                'opening_balance' => Money::fromPaise($openingBalance),
                'principal_due' => Money::fromPaise($principal),
                'interest_due' => Money::fromPaise($interest),
                'installment_amount' => Money::fromPaise($installment),
                'paid_amount' => '0.00',
                'outstanding_amount' => Money::fromPaise($installment),
                'overdue_amount' => '0.00',
                'status' => 'pending',
                'grace_until' => $dueDate->addDays($account->grace_period_days)->toDateTimeString(),
            ];
            $openingBalance -= $installment;

            return $row;
        });
    }

    public function generate(EmiAccount $account): void
    {
        $account->schedules()->createMany($this->build($account)->all());
    }
}
