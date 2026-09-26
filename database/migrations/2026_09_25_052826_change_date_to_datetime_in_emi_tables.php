<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('emi_accounts', function (Blueprint $table) {
            $table->dateTime('emi_start_date')->change();
            $table->dateTime('emi_end_date')->nullable()->change();
            $table->dateTime('next_due_date')->nullable()->change();
            $table->dateTime('last_payment_date')->nullable()->change();
        });

        Schema::table('emi_schedules', function (Blueprint $table) {
            $table->dateTime('due_date')->change();
            $table->dateTime('grace_until')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emi_accounts', function (Blueprint $table) {
            $table->date('emi_start_date')->change();
            $table->date('emi_end_date')->nullable()->change();
            $table->date('next_due_date')->nullable()->change();
            $table->date('last_payment_date')->nullable()->change();
        });

        Schema::table('emi_schedules', function (Blueprint $table) {
            $table->date('due_date')->change();
            $table->date('grace_until')->change();
        });
    }
};
