<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'customer_code', 'full_name', 'mobile_number', 'alternate_mobile_number', 'email',
        'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country',
        'identity_type', 'identity_number', 'date_of_birth', 'status', 'notes',
        'consent_given', 'consent_given_at', 'created_by', 'updated_by',
    ];

    protected $hidden = ['identity_number'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'consent_given' => 'boolean',
            'consent_given_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
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
}
