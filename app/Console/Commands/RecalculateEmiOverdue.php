<?php

namespace App\Console\Commands;

use App\Models\EmiAccount;
use App\Services\EmiOverdueService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RecalculateEmiOverdue extends Command
{
    protected $signature = 'emi:recalculate-overdue {--date= : Optional YYYY-MM-DD calculation date}';

    protected $description = 'Recalculate due and overdue statuses for active EMI accounts';

    public function handle(EmiOverdueService $overdueService): int
    {
        try {
            $asOf = $this->option('date') ? CarbonImmutable::createFromFormat('Y-m-d', $this->option('date'))->startOfDay() : CarbonImmutable::today();
        } catch (Throwable) {
            $this->error('The --date option must be a valid YYYY-MM-DD date.');

            return self::FAILURE;
        }

        $accountsProcessed = 0;
        $statusesChanged = 0;
        $overdueInstallments = 0;

        EmiAccount::query()
            ->whereIn('status', ['active', 'overdue'])
            ->chunkById(100, function ($accounts) use ($overdueService, $asOf, &$accountsProcessed, &$statusesChanged, &$overdueInstallments): void {
                foreach ($accounts as $account) {
                    $result = $overdueService->recalculate($account, $asOf);
                    $accountsProcessed++;
                    $statusesChanged += $result['schedule_statuses_changed'];
                    $overdueInstallments += $result['overdue_installments'];
                }
            });

        $this->info("Processed {$accountsProcessed} account(s); changed {$statusesChanged} schedule row(s); {$overdueInstallments} installment(s) overdue.");

        return self::SUCCESS;
    }
}
