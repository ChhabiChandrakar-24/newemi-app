<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class DevicePushToken extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'device_id', 'provider', 'token_hash', 'token_encrypted', 'platform', 'app_version', 'is_active', 'registered_at', 'last_used_at', 'last_success_at', 'last_failure_at', 'failure_count', 'invalidated_at'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'registered_at' => 'datetime', 'last_used_at' => 'datetime', 'last_success_at' => 'datetime', 'last_failure_at' => 'datetime', 'invalidated_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function token(): string
    {
        return Crypt::decryptString($this->token_encrypted);
    }
}
