<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->string('duration_type', 16)->default('months')->after('duration_months');
            $table->unsignedInteger('duration_value')->default(1)->after('duration_type');
            $table->boolean('is_trial')->default(false)->after('currency');
        });

        Schema::table('company_recharges', function (Blueprint $table): void {
            $table->string('duration_type', 16)->default('months')->after('duration_months');
            $table->unsignedInteger('duration_value')->default(1)->after('duration_type');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropColumn(['duration_type', 'duration_value', 'is_trial']);
        });

        Schema::table('company_recharges', function (Blueprint $table): void {
            $table->dropColumn(['duration_type', 'duration_value']);
        });
    }
};
