<?php

namespace App\Console\Commands;

use App\Services\CompanyRechargeService;
use Illuminate\Console\Command;

class AutoActivateQueuedSubscriptions extends Command
{
    protected $signature = 'subscription:auto-activate-queued';
    protected $description = 'Automatically activate queued subscriptions for companies whose current plan has expired';

    public function handle(CompanyRechargeService $service): int
    {
        $this->info('Checking for expired companies with queued subscription plans...');
        $count = $service->checkAndAutoActivateQueuedPlans();
        $this->info("Completed. Auto-activated {$count} queued plan(s).");
        return self::SUCCESS;
    }
}
