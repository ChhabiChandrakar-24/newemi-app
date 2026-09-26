<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Expand company_recharges statuses to varchar(32) so 'queued', 'refunded', 'reverted' work reliably
        if (Schema::hasTable('company_recharges') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE company_recharges MODIFY COLUMN recharge_status VARCHAR(32) NOT NULL DEFAULT 'active'");
            DB::statement("ALTER TABLE company_recharges MODIFY COLUMN payment_status VARCHAR(32) NOT NULL DEFAULT 'successful'");
        }

        // 2. Ensure payment_webhook_logs has all required columns and indexes
        if (Schema::hasTable('payment_webhook_logs')) {
            Schema::table('payment_webhook_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('payment_webhook_logs', 'provider')) {
                    $table->string('provider', 32)->default('razorpay')->after('id');
                }
                if (!Schema::hasColumn('payment_webhook_logs', 'event')) {
                    $table->string('event', 100)->nullable()->after('provider');
                }
                if (!Schema::hasColumn('payment_webhook_logs', 'payment_id')) {
                    $table->string('payment_id', 100)->nullable()->after('event');
                }
                if (!Schema::hasColumn('payment_webhook_logs', 'order_id')) {
                    $table->string('order_id', 100)->nullable()->after('payment_id');
                }
                if (!Schema::hasColumn('payment_webhook_logs', 'payload')) {
                    $table->longText('payload')->nullable()->after('order_id');
                }
                if (!Schema::hasColumn('payment_webhook_logs', 'status')) {
                    $table->string('status', 32)->default('received')->after('payload');
                }
                if (!Schema::hasColumn('payment_webhook_logs', 'error_message')) {
                    $table->text('error_message')->nullable()->after('status');
                }
            });
        }
    }

    public function down(): void
    {
        // No down modification needed
    }
};
