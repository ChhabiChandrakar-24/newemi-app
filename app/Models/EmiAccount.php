<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\EmiAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmiAccount extends Model
{
    /** @use HasFactory<EmiAccountFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'emi_account_code', 'customer_id', 'invoice_number', 'invoice_date',
        'product_description', 'financed_amount', 'down_payment', 'principal_amount',
        'total_installments', 'installment_amount', 'emi_frequency', 'emi_frequency_days', 'emi_start_date', 'emi_end_date', 'due_day',
        'grace_period_days', 'interest_amount', 'processing_fee', 'other_charges',
        'total_payable', 'total_paid', 'outstanding_amount', 'overdue_amount',
        'overdue_installments', 'next_due_date', 'last_payment_date', 'status', 'emi_status',
        'auto_lock_enabled', 'notes', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'financed_amount' => 'decimal:2',
            'down_payment' => 'decimal:2',
            'principal_amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'processing_fee' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'overdue_amount' => 'decimal:2',
            'invoice_date' => 'date',
            'emi_start_date' => 'datetime',
            'next_due_date' => 'datetime',
            'last_payment_date' => 'datetime',
            'auto_lock_enabled' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(EmiSchedule::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
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
}
