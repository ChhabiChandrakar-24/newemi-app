<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DevicePushToken;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DevicePushTokenService
{
    public function register(Device $device, array $data): DevicePushToken
    {
        return DB::transaction(function () use ($device, $data) {
            $hash = hash('sha256', $data['token']);
            $device->pushTokens()->where('is_active', true)->where('token_hash', '!=', $hash)->update(['is_active' => false, 'invalidated_at' => now()]);

            return DevicePushToken::updateOrCreate(['token_hash' => $hash], ['device_id' => $device->id, 'provider' => 'fcm', 'token_encrypted' => Crypt::encryptString($data['token']), 'platform' => 'android', 'app_version' => $data['app_version'] ?? null, 'is_active' => true, 'registered_at' => now(), 'invalidated_at' => null, 'failure_count' => 0]);
        });
    }

    public function revoke(Device $device): int
    {
        return $device->pushTokens()->where('is_active', true)->update(['is_active' => false, 'invalidated_at' => now()]);
    }
}
