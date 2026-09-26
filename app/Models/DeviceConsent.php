<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DeviceConsentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceConsent extends Model
{
    /** @use HasFactory<DeviceConsentFactory> */
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'customer_id', 'device_id', 'enrollment_id', 'emi_account_id',
        'consent_status', 'consent_timestamp', 'terms_version', 'privacy_version',
        'consent_device_id', 'consent_ip_address', 'enrollment_timestamp',
        'accepted_terms', 'accepted_conditions', 'accepted_privacy',
        'metadata', 'withdrawn_at',
    ];

    protected $hidden = ['consent_ip_address'];

    protected function casts(): array
    {
        return [
            'consent_timestamp' => 'datetime',
            'enrollment_timestamp' => 'datetime',
            'withdrawn_at' => 'datetime',
            'accepted_terms' => 'boolean',
            'accepted_conditions' => 'boolean',
            'accepted_privacy' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class)->withTrashed();
    }

    public function emiAccount(): BelongsTo
    {
        return $this->belongsTo(EmiAccount::class)->withTrashed();
    }
}