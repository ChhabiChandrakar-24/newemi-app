<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateLegacyTenants extends Command
{
    protected $signature = 'tenants:migrate-legacy {--company= : Existing company code to own unassigned records} {--apply : Apply safe assignments}';

    protected $description = 'Report and optionally assign legacy records without a company';

    private array $tables = ['users', 'customers', 'emi_accounts', 'payments', 'devices', 'device_enrollments', 'device_api_credentials', 'device_events', 'device_commands', 'device_push_tokens', 'device_push_attempts', 'device_locations', 'lock_policies', 'audit_logs', 'generated_reports', 'scheduled_reports', 'system_alerts', 'system_settings'];

    public function handle(): int
    {
        $company = $this->option('company') ? Company::where('company_code', $this->option('company'))->firstOrFail() : Company::where('company_code', 'CMP-000001')->firstOrFail();
        $total = 0;
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'company_id')) {
                continue;
            }
            $count = DB::table($table)->whereNull('company_id')->count();
            $total += $count;
            $this->line("{$table}: {$count} unassigned");
            if ($count && $this->option('apply')) {
                DB::table($table)->whereNull('company_id')->update(['company_id' => $company->id]);
            }
        }
        $this->info($this->option('apply') ? "Assigned {$total} records to {$company->company_code}." : "Dry run: {$total} records require assignment. Use --apply after review.");

        return self::SUCCESS;
    }
}
