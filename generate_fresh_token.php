<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$device = App\Models\Device::first();
if (!$device) {
    echo "No device found!\n";
    exit(1);
}

// Revoke previous pending enrollments
App\Models\DeviceEnrollment::where('device_id', $device->id)->where('status', 'pending')->update([
    'status' => 'revoked',
    'revoked_at' => now(),
]);

$svc = app(App\Services\DeviceEnrollmentService::class);
$admin = App\Models\User::first();
$res = $svc->createToken($device, $admin);
echo "TOKEN: " . $res['token'] . "\n";
echo "CODE: " . $res['enrollment']->enrollment_code . "\n";
echo "EXPIRES_AT: " . $res['enrollment']->expires_at . "\n";
