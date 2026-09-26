<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_recharges', function (Blueprint $table): void {
            if (!Schema::hasColumn('company_recharges', 'transferred_to_company_id')) {
                $table->foreignId('transferred_to_company_id')->nullable()->after('approved_at')->constrained('companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('company_recharges', 'transferred_from_company_id')) {
                $table->foreignId('transferred_from_company_id')->nullable()->after('transferred_to_company_id')->constrained('companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('company_recharges', 'transferred_at')) {
                $table->timestamp('transferred_at')->nullable()->after('transferred_from_company_id');
            }
            if (!Schema::hasColumn('company_recharges', 'paused_at')) {
                $table->timestamp('paused_at')->nullable()->after('transferred_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_recharges', function (Blueprint $table): void {
            if (Schema::hasColumn('company_recharges', 'transferred_to_company_id')) {
                $table->dropForeign(['transferred_to_company_id']);
                $table->dropColumn('transferred_to_company_id');
            }
            if (Schema::hasColumn('company_recharges', 'transferred_from_company_id')) {
                $table->dropForeign(['transferred_from_company_id']);
                $table->dropColumn('transferred_from_company_id');
            }
            if (Schema::hasColumn('company_recharges', 'transferred_at')) {
                $table->dropColumn('transferred_at');
            }
            if (Schema::hasColumn('company_recharges', 'paused_at')) {
                $table->dropColumn('paused_at');
            }
        });
    }
};
