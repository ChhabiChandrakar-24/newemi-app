<?php

namespace App\Console\Commands;

use App\Models\EmiAccount;
use App\Support\Money;
use Illuminate\Console\Command;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Report payment, allocation, and EMI account total inconsistencies without modifying data';

    public function handle(): int
    {
        $checked = 0;
        $issues = 0;

        EmiAccount::query()->with('payments.allocations')->chunkById(100, function ($accounts) use (&$checked, &$issues): void {
            foreach ($accounts as $account) {
                $checked++;
                $verified = $account->payments->where('status', 'verified');
                $verifiedPaise = $verified->sum(fn ($payment): int => Money::toPaise($payment->amount));
                if ($verifiedPaise !== Money::toPaise($account->total_paid)) {
                    $issues++;
                    $this->warn("{$account->emi_account_code}: account total_paid does not match verified payments.");
                }

                foreach ($verified as $payment) {
                    $cashAllocations = $payment->allocations
                        ->where('allocation_type', '!=', 'adjustment')
                        ->sum(fn ($allocation): int => Money::toPaise($allocation->allocated_amount));
                    if ($cashAllocations !== Money::toPaise($payment->amount)) {
                        $issues++;
                        $this->warn("{$payment->payment_code}: cash allocations do not equal payment amount.");
                    }
                }
            }
        });

        $this->info("Checked {$checked} account(s); found {$issues} inconsistency(ies). No data was modified.");

        return $issues === 0 ? self::SUCCESS : self::FAILURE;
    }
}
