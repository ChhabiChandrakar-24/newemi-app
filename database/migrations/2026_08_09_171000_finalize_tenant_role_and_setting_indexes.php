<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'company_id')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->dropUnique('roles_name_guard_name_unique');
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
                $table->boolean('is_system')->default(false)->after('company_id')->index();
                $table->unique(['company_id', 'name', 'guard_name'], 'roles_company_name_guard_unique');
            });
            DB::table('roles')->whereIn('name', ['super-admin', 'admin', 'manager', 'staff', 'auditor'])->update(['is_system' => true]);
        }

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropUnique('system_settings_category_key_unique');
            $table->unique(['company_id', 'category', 'key'], 'settings_company_category_key_unique');
        });
        Schema::table('system_alerts', function (Blueprint $table): void {
            $table->dropUnique('system_alerts_deduplication_key_unique');
            $table->unique(['company_id', 'deduplication_key'], 'alerts_company_dedup_unique');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropUnique('settings_company_category_key_unique');
            $table->unique(['category', 'key']);
        });
        Schema::table('system_alerts', function (Blueprint $table): void {
            $table->dropUnique('alerts_company_dedup_unique');
            $table->unique('deduplication_key');
        });
    }
};
