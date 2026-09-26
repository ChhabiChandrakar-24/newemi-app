<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DeviceReleaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceRelease extends Model
{
    /** @use HasFactory<DeviceReleaseFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'device_id', 'customer_id', 'emi_account_id', 'released_by',
        'release_reason', 'notes', 'metadata', 'release_timestamp',
    ];

    protected function casts(): array
    {
        return [
            'release_timestamp' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class)->withTrashed();
    }

    public function emiAccount(): BelongsTo
    {
        return $this->belongsTo(EmiAccount::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function releasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}