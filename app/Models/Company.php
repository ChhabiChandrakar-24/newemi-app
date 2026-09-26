<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_code', 'name', 'legal_name', 'email', 'phone', 'support_phone', 'support_email',
        'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'logo_path',
        'timezone', 'currency', 'status', 'plan', 'max_users', 'max_devices', 'subscription_status',
        'trial_ends_at', 'expires_at', 'activated_at', 'suspended_at', 'closed_at', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array', 'max_users' => 'integer', 'max_devices' => 'integer',
            'trial_ends_at' => 'datetime', 'expires_at' => 'datetime', 'activated_at' => 'datetime',
            'suspended_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function emiAccounts(): HasMany
    {
        return $this->hasMany(EmiAccount::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function lockPolicies(): HasMany
    {
        return $this->hasMany(LockPolicy::class);
    }

    public function paymentGateways(): HasMany
    {
        return $this->hasMany(PaymentGateway::class);
    }

    public function deviceManagementSettings(): HasOne
    {
        return $this->hasOne(DeviceManagementSetting::class);
    }

    public function recharges(): HasMany
    {
        return $this->hasMany(CompanyRecharge::class);
    }

    public function latestRecharge(): HasOne
    {
        return $this->hasOne(CompanyRecharge::class)->latestOfMany();
    }
}
