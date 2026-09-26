<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emi_accounts', fn (Blueprint $t) => $t->index(['overdue_amount', 'status'], 'emi_overdue_status_index'));
        Schema::table('payments', fn (Blueprint $t) => $t->index(['payment_date', 'status', 'payment_type'], 'payment_report_index'));
        Schema::table('device_commands', fn (Blueprint $t) => $t->index(['status', 'requested_at', 'command_type'], 'command_report_index'));
    }

    public function down(): void
    {
        Schema::table('emi_accounts', fn (Blueprint $t) => $t->dropIndex('emi_overdue_status_index'));
        Schema::table('payments', fn (Blueprint $t) => $t->dropIndex('payment_report_index'));
        Schema::table('device_commands', fn (Blueprint $t) => $t->dropIndex('command_report_index'));
    }
};
