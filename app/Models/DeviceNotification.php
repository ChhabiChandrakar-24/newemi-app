<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DeviceNotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceNotification extends Model
{
    /** @use HasFactory<DeviceNotificationFactory> */
    use BelongsToCompany, HasFactory;

    public const UPDATED_AT = null;

    public const QUEUED = 'queued';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    public const CHANNEL_PUSH = 'push';
    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_SYSTEM = 'system';

    public const TYPE_EMI_DUE = 'EMI_DUE';
    public const TYPE_EMI_OVERDUE = 'EMI_OVERDUE';
    public const TYPE_GRACE_PERIOD = 'GRACE_PERIOD';
    public const TYPE_FINAL_WARNING = 'FINAL_WARNING';
    public const TYPE_RESTRICTION = 'RESTRICTION';
    public const TYPE_PAYMENT_RECEIVED = 'PAYMENT_RECEIVED';
    public const TYPE_EMI_COMPLETED = 'EMI_COMPLETED';
    public const TYPE_DEVICE_RELEASED = 'DEVICE_RELEASED';
    public const TYPE_DEVICE_UNENROLLED = 'DEVICE_UNENROLLED';
    public const TYPE_RE_ENROLLMENT_REQUIRED = 'RE_ENROLLMENT_REQUIRED';

    protected $fillable = [
        'company_id', 'device_id', 'customer_id', 'emi_account_id',
        'notification_type', 'recipient_type', 'title', 'message',
        'delivery_status', 'channel', 'delivered_at', 'deduplication_key', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function emiAccount(): BelongsTo
    {
        return $this->belongsTo(EmiAccount::class)->withTrashed();
    }
}