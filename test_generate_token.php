<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Device;
use App\Models\DeviceManagementSetting;
use App\Services\DeviceEnrollmentService;

$admin = User::where('mobile_number', '9981887943')->orWhere('email', 'like', '%chhabi%')->first() ?? User::first();
$device = Device::where('id', 7)->first() ?? Device::latest()->first();

if (!$device) {
    echo "No device found\n";
    exit(1);
}

// Ensure company has settings with 120 minutes TTL
DeviceManagementSetting::updateOrCreate(
    ['company_id' => $device->company_id],
    [
        'enrollment_token_ttl_minutes' => 120,
        'heartbeat_interval_minutes' => 15,
        'heartbeat_timeout_minutes' => 30,
        'offline_alert_after_minutes' => 60,
        'command_ttl_minutes' => 60,
        'command_max_retries' => 3,
        'command_retry_delay_seconds' => 30,
        'polling_fallback_enabled' => true,
        'fcm_wakeup_enabled' => true,
        'require_device_management_consent' => true,
        'require_device_owner_for_lock' => false,
        'allowed_management_modes' => ['device_owner', 'profile_owner', 'fully_managed', 'device_admin'],
        'auto_unlock_after_payment' => true,
        'auto_lock_action' => 'full_lock',
        'require_reason_for_warning' => false,
        'require_reason_for_partial_lock' => false,
        'require_reason_for_full_lock' => false,
        'require_reason_for_unlock' => false,
        'warning_message' => 'Your EMI payment requires attention.',
        'partial_lock_message' => 'Some device features are restricted. Contact support.',
        'full_lock_message' => 'This managed device is restricted. Contact support to resolve your EMI account.',
        'factory_reset_restriction_enabled' => true,
        'safe_boot_restriction_enabled' => true,
        'compliance_monitoring_enabled' => true,
        'management_loss_alert_enabled' => true,
        'sim_change_alert_enabled' => true,
        'agent_outdated_alert_enabled' => true,
        'release_requires_completed_emi' => true,
        'release_requires_admin_approval' => false,
    ]
);

// Make sure device is eligible for enrollment
$device->update([
    'enrollment_status' => 'pending',
    'control_status' => 'normal',
]);

$res = app(DeviceEnrollmentService::class)->createToken($device, $admin);

echo json_encode([
    'success' => true,
    'device_id' => $device->id,
    'brand' => $device->brand,
    'model' => $device->model,
    'enrollment_code' => $res['enrollment']->enrollment_code,
    'token' => $res['token'],
    'expires_at' => $res['enrollment']->expires_at->toDateTimeString(),
], JSON_PRETTY_PRINT);
