<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'payment_code', 'emi_account_id', 'customer_id', 'payment_date', 'amount',
        'payment_method', 'transaction_reference', 'external_reference', 'receipt_number',
        'idempotency_key', 'payment_type', 'status', 'notes', 'reversal_reason',
        'collected_by', 'verified_by', 'verified_at', 'created_by', 'updated_by',
    ];

    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    public function emiAccount(): BelongsTo
    {
        return $this->belongsTo(EmiAccount::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by')->withTrashed();
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by')->withTrashed();
    }
}
