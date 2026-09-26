<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_management_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('enrollment_token_ttl_minutes')->default(30);
            $table->unsignedInteger('heartbeat_interval_minutes')->default(15);
            $table->unsignedInteger('heartbeat_timeout_minutes')->default(30);
            $table->unsignedInteger('offline_alert_after_minutes')->default(60);
            $table->unsignedInteger('command_ttl_minutes')->default(60);
            $table->unsignedInteger('command_max_retries')->default(3);
            $table->unsignedInteger('command_retry_delay_seconds')->default(30);
            $table->boolean('polling_fallback_enabled')->default(true);
            $table->boolean('fcm_wakeup_enabled')->default(true);
            $table->string('minimum_android_version', 30)->nullable();
            $table->string('minimum_agent_version', 30)->nullable();
            $table->string('recommended_agent_version', 30)->nullable();
            $table->boolean('require_device_management_consent')->default(true);
            $table->boolean('require_device_owner_for_lock')->nullable()->default(true);
            $table->json('allowed_management_modes');
            $table->foreignId('default_lock_policy_id')->nullable()->constrained('lock_policies')->nullOnDelete();
            $table->boolean('auto_unlock_after_payment')->default(true);
            $table->boolean('require_reason_for_warning')->default(true);
            $table->boolean('require_reason_for_partial_lock')->default(true);
            $table->boolean('require_reason_for_full_lock')->default(true);
            $table->boolean('require_reason_for_unlock')->default(true);
            $table->text('warning_message')->nullable();
            $table->text('partial_lock_message')->nullable();
            $table->text('full_lock_message')->nullable();
            $table->string('support_phone', 32)->nullable();
            $table->string('support_email')->nullable();
            $table->boolean('factory_reset_restriction_enabled')->default(false);
            $table->boolean('safe_boot_restriction_enabled')->default(false);
            $table->boolean('unknown_sources_restriction_enabled')->default(false);
            $table->boolean('compliance_monitoring_enabled')->default(true);
            $table->boolean('management_loss_alert_enabled')->default(true);
            $table->boolean('sim_change_alert_enabled')->default(true);
            $table->boolean('agent_outdated_alert_enabled')->default(true);
            $table->boolean('release_requires_completed_emi')->default(true);
            $table->boolean('release_requires_admin_approval')->default(true);
            $table->boolean('location_feature_enabled')->default(false);
            $table->boolean('foreground_location_allowed')->default(false);
            $table->boolean('background_location_allowed')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_management_settings');
    }
};
