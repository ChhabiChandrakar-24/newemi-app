<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevicePushAttempt extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'device_id', 'device_command_id', 'provider', 'status', 'provider_message_id', 'attempted_at', 'succeeded_at', 'failed_at', 'failure_code', 'failure_message'];

    protected function casts(): array
    {
        return ['attempted_at' => 'datetime', 'succeeded_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceCommand::class, 'device_command_id');
    }
}
