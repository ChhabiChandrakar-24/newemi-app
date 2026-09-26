<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceManagementSetting extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'allowed_management_modes' => 'array',
            'polling_fallback_enabled' => 'boolean', 'fcm_wakeup_enabled' => 'boolean',
            'require_device_management_consent' => 'boolean', 'require_device_owner_for_lock' => 'boolean',
            'auto_unlock_after_payment' => 'boolean', 'require_reason_for_warning' => 'boolean',
            'require_reason_for_partial_lock' => 'boolean', 'require_reason_for_full_lock' => 'boolean',
            'require_reason_for_unlock' => 'boolean',
            'warning_message' => 'string',
            'partial_lock_message' => 'string',
            'full_lock_message' => 'string',
            'support_phone' => 'string',
            'support_email' => 'string',
            'shop_upi_id' => 'string',
            'factory_reset_restriction_enabled' => 'boolean',
            'safe_boot_restriction_enabled' => 'boolean', 'unknown_sources_restriction_enabled' => 'boolean',
            'compliance_monitoring_enabled' => 'boolean', 'management_loss_alert_enabled' => 'boolean',
            'sim_change_alert_enabled' => 'boolean', 'agent_outdated_alert_enabled' => 'boolean',
            'release_requires_completed_emi' => 'boolean', 'release_requires_admin_approval' => 'boolean',
            'location_feature_enabled' => 'boolean', 'foreground_location_allowed' => 'boolean',
            'background_location_allowed' => 'boolean',
            'progressive_warning_enabled' => 'boolean',
            'progressive_warning_count' => 'integer',
            'warning_escalation_interval_value' => 'integer',
        ];
    }

    public function defaultLockPolicy(): BelongsTo
    {
        return $this->belongsTo(LockPolicy::class, 'default_lock_policy_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
