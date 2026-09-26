<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyRecharge extends Model
{
    use HasFactory;

    protected $fillable = [
        'recharge_code',
        'company_id',
        'plan_id',
        'plan_name',
        'duration_months',
        'duration_type',
        'duration_value',
        'device_limit',
        'amount',
        'promo_code',
        'discount_amount',
        'original_amount',
        'currency',
        'payment_method',
        'payment_reference',
        'payment_status',
        'recharge_status',
        'starts_at',
        'expires_at',
        'notes',
        'created_by',
        'approved_by',
        'approved_at',
        'transferred_to_company_id',
        'transferred_from_company_id',
        'transferred_at',
        'transfer_requested_at',
        'transfer_status',
        'transfer_notes',
        'transfer_rejection_reason',
        'transfer_approved_by',
        'paused_at',
        'reverted_at',
        'reverted_by',
        'revert_reason',
    ];

    protected function casts(): array
    {
        return [
            'duration_months' => 'integer',
            'duration_value' => 'integer',
            'device_limit' => 'integer',
            'amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'original_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'transferred_at' => 'datetime',
            'transfer_requested_at' => 'datetime',
            'paused_at' => 'datetime',
            'reverted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transferredToCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'transferred_to_company_id');
    }

    public function transferredFromCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'transferred_from_company_id');
    }

    public function transferApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transfer_approved_by');
    }

    public function revertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reverted_by');
    }
}
