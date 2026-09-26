<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentGateway extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    public const PROVIDERS = ['razorpay', 'cashfree', 'stripe', 'payu', 'phonepe', 'custom'];

    protected $fillable = [
        'company_id', 'webhook_key', 'provider', 'display_name', 'environment', 'is_enabled', 'is_default',
        'public_key', 'encrypted_secret', 'encrypted_webhook_secret', 'config', 'status', 'last_tested_at',
        'last_test_status', 'created_by', 'updated_by',
    ];

    protected $hidden = ['encrypted_secret', 'encrypted_webhook_secret', 'config'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean', 'is_default' => 'boolean', 'encrypted_secret' => 'encrypted',
            'encrypted_webhook_secret' => 'encrypted', 'config' => 'encrypted:array', 'last_tested_at' => 'datetime',
        ];
    }

    public function safePayload(): array
    {
        return [
            ...$this->only(['id', 'webhook_key', 'provider', 'display_name', 'environment', 'is_enabled', 'is_default', 'public_key', 'status', 'last_tested_at', 'last_test_status', 'created_at', 'updated_at']),
            'secret_configured' => filled($this->encrypted_secret),
            'webhook_secret_configured' => filled($this->encrypted_webhook_secret),
            'config_fields' => collect($this->config ?? [])->keys()->values(),
        ];
    }
}
