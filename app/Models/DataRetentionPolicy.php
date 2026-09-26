<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DataRetentionPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataRetentionPolicy extends Model
{
    /** @use HasFactory<DataRetentionPolicyFactory> */
    use BelongsToCompany, HasFactory;

    public const DATA_TYPES = [
        'device_events', 'device_notifications', 'device_locations',
        'device_commands', 'device_consents', 'audit_logs',
    ];

    public const ACTIONS = ['delete', 'anonymize', 'keep'];

    protected $fillable = [
        'company_id', 'data_type', 'retention_period_days', 'action',
        'reason', 'is_active', 'last_applied_at',
    ];

    protected function casts(): array
    {
        return [
            'retention_period_days' => 'integer',
            'is_active' => 'boolean',
            'last_applied_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}