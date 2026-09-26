<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceCommand extends Model
{
    use BelongsToCompany;

    public const TYPES = ['show_warning', 'partial_lock', 'full_lock', 'lock', 'unlock', 'release', 'policy_sync', 'refresh_status', 'release_prepare'];

    public const STATUSES = ['queued', 'dispatched', 'received', 'acknowledged', 'applied', 'failed', 'expired', 'cancelled'];

    public const ACTIVE_STATUSES = ['queued', 'dispatched', 'received', 'acknowledged'];

    protected $fillable = [
        'company_id', 'command_uuid', 'device_id', 'command_type', 'requested_control_status',
        'payload', 'status', 'priority', 'source', 'requested_by', 'requested_at',
        'available_at', 'next_attempt_at', 'sent_at', 'received_at',
        'acknowledged_at', 'applied_at', 'failed_at', 'expires_at', 'retry_count',
        'max_retries', 'failure_code', 'failure_message', 'result_message', 'remarks',
        'idempotency_key', 'correlation_id', 'result_payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'result_payload' => 'array',
            'requested_at' => 'datetime', 'available_at' => 'datetime',
            'next_attempt_at' => 'datetime', 'sent_at' => 'datetime',
            'received_at' => 'datetime', 'acknowledged_at' => 'datetime',
            'applied_at' => 'datetime', 'failed_at' => 'datetime', 'expires_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function pushAttempts(): HasMany
    {
        return $this->hasMany(DevicePushAttempt::class);
    }
}
