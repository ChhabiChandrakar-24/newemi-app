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
            $table->string('emi_frequency')->default('monthly')->after('installment_amount');
            $table->integer('emi_frequency_days')->nullable()->after('emi_frequency');
            $table->date('emi_end_date')->nullable()->after('emi_start_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emi_accounts', function (Blueprint $table) {
            $table->dropColumn(['emi_frequency', 'emi_frequency_days', 'emi_end_date']);
        });
    }
};
