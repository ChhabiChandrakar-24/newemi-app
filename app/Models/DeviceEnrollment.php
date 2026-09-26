<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceEnrollment extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'enrollment_code', 'device_id', 'token_hash', 'expires_at', 'used_at', 'revoked_at', 'status', 'created_by', 'metadata'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime', 'metadata' => 'array'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
