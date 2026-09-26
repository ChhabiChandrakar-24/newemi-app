<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_management_settings', function (Blueprint $table): void {
            $table->boolean('progressive_warning_enabled')->default(true)->after('auto_unlock_after_payment');
            $table->unsignedInteger('progressive_warning_count')->default(3)->after('progressive_warning_enabled');
            $table->string('warning_escalation_interval_unit', 20)->default('minutes')->after('progressive_warning_count');
            $table->unsignedInteger('warning_escalation_interval_value')->default(1)->after('warning_escalation_interval_unit');
            $table->string('auto_lock_action', 30)->default('full_lock')->after('warning_escalation_interval_value');
        });
    }

    public function down(): void
    {
        Schema::table('device_management_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'progressive_warning_enabled',
                'progressive_warning_count',
                'warning_escalation_interval_unit',
                'warning_escalation_interval_value',
                'auto_lock_action',
            ]);
        });
    }
};
