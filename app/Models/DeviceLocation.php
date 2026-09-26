<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceLocation extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = ['company_id', 'device_id', 'latitude', 'longitude', 'accuracy_meters', 'captured_at', 'received_at', 'source', 'tracking_mode', 'created_at'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'accuracy_meters' => 'decimal:2', 'captured_at' => 'datetime', 'received_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
