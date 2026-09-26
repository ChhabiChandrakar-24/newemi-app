<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmLead extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'lead_code',
        'customer_name',
        'shop_name',
        'phone',
        'alternate_phone',
        'email',
        'address',
        'city',
        'state',
        'lead_type',
        'status',
        'priority',
        'assigned_to',
        'estimated_devices',
        'estimated_budget',
        'next_followup_at',
        'notes',
        'lost_reason',
        'converted_customer_id',
        'converted_at',
        'created_by',
        'updated_by',
    ];

    protected $appends = ['name', 'business_name', 'estimated_value', 'follow_up_date', 'code'];

    protected function casts(): array
    {
        return [
            'estimated_devices' => 'integer',
            'estimated_budget' => 'decimal:2',
            'next_followup_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function getNameAttribute(): string
    {
        return $this->customer_name ?? '';
    }

    public function getBusinessNameAttribute(): ?string
    {
        return $this->shop_name;
    }

    public function getEstimatedValueAttribute(): float
    {
        return (float) ($this->estimated_budget ?? 0);
    }

    public function getFollowUpDateAttribute(): ?string
    {
        return $this->next_followup_at?->format('Y-m-d');
    }

    public function getCodeAttribute(): string
    {
        return $this->lead_code ?? '';
    }

    public function assignedSalesPerson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function convertedCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(CrmVisit::class, 'lead_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(CrmProject::class, 'lead_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
