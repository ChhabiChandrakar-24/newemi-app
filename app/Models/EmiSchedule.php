<?php

namespace App\Models;

use Database\Factories\EmiScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmiSchedule extends Model
{
    /** @use HasFactory<EmiScheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'emi_account_id', 'installment_number', 'due_date', 'opening_balance',
        'principal_due', 'interest_due', 'installment_amount', 'paid_amount',
        'outstanding_amount', 'overdue_amount', 'paid_at', 'status', 'grace_until',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'principal_due' => 'decimal:2',
            'interest_due' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'overdue_amount' => 'decimal:2',
            'due_date' => 'datetime',
            'grace_until' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function emiAccount(): BelongsTo
    {
        return $this->belongsTo(EmiAccount::class);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
