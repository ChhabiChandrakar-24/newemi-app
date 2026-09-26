<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'device_code', 'customer_id', 'emi_account_id', 'display_name', 'brand', 'model',
        'manufacturer', 'android_version', 'sdk_version', 'serial_number', 'imei1', 'imei2',
        'internal_device_uuid', 'installation_id_hash', 'invoice_number', 'app_version',
        'management_mode', 'enrollment_status', 'management_status', 'control_status', 'desired_control_status',
        'device_lock_status', 'lock_policy_id', 'policy_automation_paused', 'connectivity_status', 'connection_status',
        'location_tracking_enabled', 'location_tracking_mode', 'location_permission_state',
        'location_consent_given_at', 'location_consent_withdrawn_at',
        'compliance_status', 'last_seen_at', 'last_heartbeat_at', 'last_ip_address',
        'battery_level', 'battery_charging', 'network_type', 'sim_state', 'sim_fingerprint',
        'policy_version', 'capabilities', 'consent_verified_at', 'activated_at',
        'released_at', 'privacy_erased_at', 'notes', 'created_by', 'updated_by',
    ];

    protected $hidden = ['installation_id_hash', 'sim_fingerprint'];

    protected static function booted(): void
    {
        static::saving(function (Device $device): void {
            // Keep connection_status and connectivity_status synchronized
            if ($device->isDirty('connection_status') && ! $device->isDirty('connectivity_status')) {
                $device->connectivity_status = match (strtoupper((string) $device->connection_status)) {
                    'ONLINE' => 'online',
                    'OFFLINE' => 'offline',
                    default => 'unknown',
                };
            } elseif ($device->isDirty('connectivity_status') && ! $device->isDirty('connection_status')) {
                $device->connection_status = match (strtolower((string) $device->connectivity_status)) {
                    'online' => 'ONLINE',
                    'offline' => 'OFFLINE',
                    default => 'UNKNOWN',
                };
            }

            // Keep device_lock_status and control_status synchronized
            if ($device->isDirty('device_lock_status') && ! $device->isDirty('control_status')) {
                $device->control_status = match (strtoupper((string) $device->device_lock_status)) {
                    'LOCKED' => 'full_lock',
                    'LOCK_PENDING' => 'partial_lock',
                    default => 'normal',
                };
                $device->device_lock_status = match ($device->control_status) {
                    'full_lock', 'locked' => 'LOCKED',
                    'partial_lock', 'lock_pending' => 'LOCK_PENDING',
                    default => 'UNLOCKED',
                };
            }

            // Keep imei and imei1 synchronized
            if ($device->isDirty('imei') && ! $device->isDirty('imei1')) {
                $device->imei1 = $device->imei;
            } elseif ($device->isDirty('imei1') && ! $device->isDirty('imei')) {
                $device->imei = $device->imei1;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'sdk_version' => 'integer',
            'battery_level' => 'integer',
            'battery_charging' => 'boolean',
            'policy_automation_paused' => 'boolean',
            'location_tracking_enabled' => 'boolean', 'location_consent_given_at' => 'datetime',
            'location_consent_withdrawn_at' => 'datetime',
            'capabilities' => 'array',
            'last_seen_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'consent_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'released_at' => 'datetime',
            'privacy_erased_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id')->withTrashed();
    }

    public function activeEnrollment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DeviceEnrollment::class)->latestOfMany();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed()->withoutGlobalScopes();
    }

    public function emiAccount(): BelongsTo
    {
        return $this->belongsTo(EmiAccount::class)->withTrashed()->withoutGlobalScopes();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(DeviceEnrollment::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(DeviceApiCredential::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceEvent::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }

    public function lockPolicy(): BelongsTo
    {
        return $this->belongsTo(LockPolicy::class);
    }

    public function pushTokens(): HasMany
    {
        return $this->hasMany(DevicePushToken::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(DeviceLocation::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(DeviceConsent::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(DeviceNotification::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(DeviceRelease::class);
    }

    public function getImeiAttribute(): ?string
    {
        return $this->attributes['imei1'] ?? $this->attributes['imei'] ?? null;
    }

    public function setImeiAttribute(?string $value): void
    {
        $this->attributes['imei1'] = $value;
        if (\Illuminate\Support\Facades\Schema::hasColumn('devices', 'imei')) {
            $this->attributes['imei'] = $value;
        }
    }
}
