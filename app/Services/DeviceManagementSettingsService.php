<?php

namespace App\Services;

use App\Models\DeviceManagementSetting;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeviceManagementSettingsService
{
    public const DEFAULTS = [
        'enrollment_token_ttl_minutes' => 30, 'heartbeat_interval_minutes' => 15,
        'heartbeat_timeout_minutes' => 30, 'offline_alert_after_minutes' => 60,
        'command_ttl_minutes' => 60, 'command_max_retries' => 3, 'command_retry_delay_seconds' => 30,
        'polling_fallback_enabled' => true, 'fcm_wakeup_enabled' => true,
        'minimum_android_version' => null, 'minimum_agent_version' => null, 'recommended_agent_version' => null,
        'require_device_management_consent' => true, 'require_device_owner_for_lock' => true,
        'allowed_management_modes' => ['device_owner', 'fully_managed'], 'default_lock_policy_id' => null,
        'auto_unlock_after_payment' => true, 'require_reason_for_warning' => true,
        'require_reason_for_partial_lock' => true, 'require_reason_for_full_lock' => true,
        'require_reason_for_unlock' => true, 'warning_message' => 'Your EMI payment requires attention.',
        'partial_lock_message' => 'Some device features are restricted. Contact support.',
        'full_lock_message' => 'This managed device is restricted. Contact support to resolve your EMI account.',
        'support_phone' => null, 'support_email' => null,
        'factory_reset_restriction_enabled' => true, 'safe_boot_restriction_enabled' => true,
        'unknown_sources_restriction_enabled' => false, 'compliance_monitoring_enabled' => true,
        'management_loss_alert_enabled' => true, 'sim_change_alert_enabled' => true,
        'agent_outdated_alert_enabled' => true, 'release_requires_completed_emi' => true,
        'release_requires_admin_approval' => true, 'location_feature_enabled' => false,
        'foreground_location_allowed' => false, 'background_location_allowed' => false,
        'progressive_warning_enabled' => true, 'progressive_warning_count' => 3,
        'warning_escalation_interval_unit' => 'minutes', 'warning_escalation_interval_value' => 1,
        'auto_lock_action' => 'full_lock',
    ];

    public function __construct(private readonly AuditService $audit) {}

    public function current(): DeviceManagementSetting
    {
        $companyId = app(TenantContext::class)->id() ?? Auth::user()?->company_id;

        return $this->forCompany((int) $companyId);
    }

    public function forCompany(int $companyId): DeviceManagementSetting
    {
        return DeviceManagementSetting::withoutGlobalScopes()->firstOrCreate(['company_id' => $companyId], self::DEFAULTS);
    }

    public function update(array $values, User $actor): DeviceManagementSetting
    {
        return DB::transaction(function () use ($values, $actor): DeviceManagementSetting {
            $companyId = app(TenantContext::class)->id() ?? Auth::user()?->company_id;
            $settings = DeviceManagementSetting::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->first();
            $settings ??= $this->forCompany((int) $companyId);
            $old = $settings->only(array_keys(self::DEFAULTS));
            $settings->update([...Arr::only($values, array_keys(self::DEFAULTS)), 'updated_by' => $actor->id]);
            $this->audit->record('device_management_settings.updated', $settings, $old, $settings->fresh()->only(array_keys(self::DEFAULTS)));

            return $settings->fresh('updater');
        });
    }

    public function reset(array $keys, User $actor): DeviceManagementSetting
    {
        $values = $keys ? Arr::only(self::DEFAULTS, $keys) : self::DEFAULTS;
        $settings = $this->update($values, $actor);
        $this->audit->record('device_management_settings.reset', $settings, null, ['keys' => array_keys($values)], 'Selected settings reset to safe defaults.');

        return $settings;
    }
}
