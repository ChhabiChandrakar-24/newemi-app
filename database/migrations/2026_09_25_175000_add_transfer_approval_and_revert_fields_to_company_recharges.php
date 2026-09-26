<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_recharges', function (Blueprint $table): void {
            if (!Schema::hasColumn('company_recharges', 'transfer_requested_at')) {
                $table->timestamp('transfer_requested_at')->nullable()->after('transferred_at');
            }
            if (!Schema::hasColumn('company_recharges', 'transfer_status')) {
                $table->string('transfer_status', 30)->nullable()->after('transfer_requested_at');
            }
            if (!Schema::hasColumn('company_recharges', 'transfer_notes')) {
                $table->text('transfer_notes')->nullable()->after('transfer_status');
            }
            if (!Schema::hasColumn('company_recharges', 'transfer_rejection_reason')) {
                $table->string('transfer_rejection_reason', 500)->nullable()->after('transfer_notes');
            }
            if (!Schema::hasColumn('company_recharges', 'transfer_approved_by')) {
                $table->foreignId('transfer_approved_by')->nullable()->after('transfer_rejection_reason')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('company_recharges', 'reverted_at')) {
                $table->timestamp('reverted_at')->nullable()->after('paused_at');
            }
            if (!Schema::hasColumn('company_recharges', 'reverted_by')) {
                $table->foreignId('reverted_by')->nullable()->after('reverted_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('company_recharges', 'revert_reason')) {
                $table->text('revert_reason')->nullable()->after('reverted_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_recharges', function (Blueprint $table): void {
            if (Schema::hasColumn('company_recharges', 'transfer_approved_by')) {
                $table->dropForeign(['transfer_approved_by']);
                $table->dropColumn('transfer_approved_by');
            }
            if (Schema::hasColumn('company_recharges', 'reverted_by')) {
                $table->dropForeign(['reverted_by']);
                $table->dropColumn('reverted_by');
            }
            $table->dropColumn([
                'transfer_requested_at',
                'transfer_status',
                'transfer_notes',
                'transfer_rejection_reason',
                'reverted_at',
                'revert_reason',
            ]);
        });
    }
};
