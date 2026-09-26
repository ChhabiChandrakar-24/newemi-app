<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $defaults = [
            'notifications' => [
                'overdue_alert' => true, 'device_offline_alert' => true,
                'command_failure_alert' => true, 'tamper_alert' => true,
                'sim_change_alert' => true, 'payment_received_alert' => true,
            ],
            'security' => [
                'session_timeout' => 30, 'login_attempt_limit' => 5,
                'require_full_lock_remarks' => true, 'require_unlock_remarks' => true,
                'high_risk_confirmation' => true,
            ],
        ];
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach ($defaults as $category => $values) {
                foreach ($values as $key => $value) {
                    DB::table('system_settings')->updateOrInsert(
                        ['company_id' => $companyId, 'category' => $category, 'key' => $key],
                        ['value' => json_encode($value), 'is_secret' => false, 'is_enabled' => true, 'updated_at' => now(), 'created_at' => now()],
                    );
                }
            }
        }
    }

    public function down(): void {}
};
