<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckTenantIntegrity extends Command
{
    protected $signature = 'tenants:check-integrity';

    protected $description = 'Detect missing and cross-company tenant relationships without modifying data';

    public function handle(): int
    {
        $checks = [
            'emi_customer' => ['emi_accounts e', 'customers c', 'e.customer_id=c.id', 'e.company_id<>c.company_id'],
            'payment_account' => ['payments p', 'emi_accounts e', 'p.emi_account_id=e.id', 'p.company_id<>e.company_id'],
            'payment_customer' => ['payments p', 'customers c', 'p.customer_id=c.id', 'p.company_id<>c.company_id'],
            'device_customer' => ['devices d', 'customers c', 'd.customer_id=c.id', 'd.company_id<>c.company_id'],
            'device_account' => ['devices d', 'emi_accounts e', 'd.emi_account_id=e.id', 'd.company_id<>e.company_id'],
            'command_device' => ['device_commands x', 'devices d', 'x.device_id=d.id', 'x.company_id<>d.company_id'],
            'location_device' => ['device_locations x', 'devices d', 'x.device_id=d.id', 'x.company_id<>d.company_id'],
            'enrollment_device' => ['device_enrollments x', 'devices d', 'x.device_id=d.id', 'x.company_id<>d.company_id'],
            'credential_device' => ['device_api_credentials x', 'devices d', 'x.device_id=d.id', 'x.company_id<>d.company_id'],
            'event_device' => ['device_events x', 'devices d', 'x.device_id=d.id', 'x.company_id<>d.company_id'],
        ];
        $issues = 0;
        foreach ($checks as $name => [$left, $right, $join, $where]) {
            $count = DB::table(DB::raw("{$left} JOIN {$right} ON {$join}"))->whereRaw($where)->count();
            $issues += $count;
            $this->line("{$name}: {$count}");
        }
        foreach (['users', 'customers', 'emi_accounts', 'payments', 'devices', 'device_enrollments', 'device_api_credentials', 'device_commands', 'device_events', 'device_locations', 'lock_policies', 'payment_gateways', 'scheduled_reports', 'generated_reports', 'system_alerts'] as $table) {
            $query = DB::table($table)->whereNull('company_id');
            if ($table === 'users') {
                $query->where('is_platform_admin', false);
            }
            $count = $query->count();
            $issues += $count;
            $this->line("{$table}_missing_company: {$count}");
        }
        $this->info($issues ? "FAILED: {$issues} cross-company relationships detected." : 'PASS: no cross-company relationships detected.');

        return $issues ? self::FAILURE : self::SUCCESS;
    }
}
