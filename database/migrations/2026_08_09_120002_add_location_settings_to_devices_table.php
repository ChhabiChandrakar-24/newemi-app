<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->boolean('location_tracking_enabled')->default(false)->after('policy_automation_paused')->index();
            $table->string('location_tracking_mode', 30)->default('disabled')->after('location_tracking_enabled');
            $table->string('location_permission_state', 30)->default('not_requested')->after('location_tracking_mode');
            $table->timestamp('location_consent_given_at')->nullable()->after('location_permission_state');
            $table->timestamp('location_consent_withdrawn_at')->nullable()->after('location_consent_given_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn(['location_tracking_enabled', 'location_tracking_mode', 'location_permission_state', 'location_consent_given_at', 'location_consent_withdrawn_at']));
    }
};
