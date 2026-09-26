<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SettingsService
{
    public const SECRET_KEYS = ['password', 'api_key', 'api_secret', 'secret', 'webhook_secret', 'service_account_json', 'server_key'];

    public function category(string $category): array
    {
        return SystemSetting::query()->where('category', $category)->get()->mapWithKeys(function (SystemSetting $setting): array {
            if ($setting->is_secret) {
                return [$setting->key => ['configured' => filled($setting->value), 'masked_value' => filled($setting->value) ? '********' : null]];
            }

            return [$setting->key => $this->decode($setting->value)];
        })->all();
    }

    public function update(string $category, array $values, User $actor, array $remove = []): array
    {
        foreach ($values as $key => $value) {
            $secret = in_array($key, self::SECRET_KEYS, true);
            $existing = SystemSetting::query()->firstOrNew(compact('category', 'key'));
            if ($secret && ($value === null || $value === '')) {
                continue;
            }
            $existing->fill(['is_secret' => $secret, 'value' => $secret ? Crypt::encryptString((string) $value) : json_encode($value), 'updated_by' => $actor->id])->save();
        }
        SystemSetting::query()->where('category', $category)->whereIn('key', array_intersect($remove, self::SECRET_KEYS))->update(['value' => null, 'updated_by' => $actor->id]);
        Cache::forget("settings.$category");

        return $this->category($category);
    }

    public function secret(string $category, string $key): ?string
    {
        $value = SystemSetting::query()->where(compact('category', 'key'))->where('is_secret', true)->value('value');

        return $value ? Crypt::decryptString($value) : null;
    }

    private function decode(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }
}
