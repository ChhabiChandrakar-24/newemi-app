<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\LockPolicy;
use Illuminate\Database\Seeder;

class LockPolicySeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('company_code', 'CMP-000001')->firstOrFail();
        LockPolicy::withoutGlobalScopes()->firstOrCreate(['company_id' => $company->id, 'name' => 'Default EMI Policy'], [
            'is_default' => true, 'is_active' => true, 'warning_after_overdue_days' => 3,
            'partial_lock_after_overdue_days' => 7, 'full_lock_after_overdue_days' => 15,
            'unlock_on_payment_clearance' => true, 'offline_behavior' => 'defer',
        ]);
    }
}
