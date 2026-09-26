<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetTestDataCommand extends Command
{
    protected $signature = 'app:reset-test-data {--force : Force reset without confirmation prompt}';
    protected $description = 'Clean all test devices, EMI accounts, payments, and CRM records while preserving admin users and gateway settings.';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('This will wipe all test devices, EMI accounts, payments, and CRM test data. Continue?')) {
            $this->warn('Operation cancelled.');
            return self::SUCCESS;
        }

        $this->info('Resetting test data...');

        Schema::disableForeignKeyConstraints();

        $tablesToClear = [
            'device_commands',
            'device_events',
            'device_locations',
            'device_enrollments',
            'device_consents',
            'device_api_credentials',
            'device_push_tokens',
            'device_push_attempts',
            'device_releases',
            'device_notifications',
            'devices',
            'payment_allocations',
            'payments',
            'emi_schedules',
            'emi_accounts',
            'customers',
            'crm_messages',
            'crm_visits',
            'crm_leads',
            'crm_projects',
            'audit_logs',
        ];

        foreach ($tablesToClear as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
                $this->line("  ✓ Cleared table: {$table}");
            }
        }

        Schema::enableForeignKeyConstraints();

        $this->info('All test data has been successfully cleared!');
        $this->info('You can now register a fresh device and test complete A-to-Z lifecycle.');

        return self::SUCCESS;
    }
}
