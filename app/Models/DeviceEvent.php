<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceEvent extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'device_id', 'event_type', 'severity', 'event_time', 'payload', 'acknowledged_at', 'acknowledged_by'];

    protected function casts(): array
    {
        return ['event_time' => 'datetime', 'payload' => 'array', 'acknowledged_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
