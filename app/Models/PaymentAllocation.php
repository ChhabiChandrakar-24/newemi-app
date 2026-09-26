<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    protected $fillable = ['payment_id', 'emi_schedule_id', 'allocated_amount', 'allocation_type'];

    protected function casts(): array
    {
        return ['allocated_amount' => 'decimal:2'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function emiSchedule(): BelongsTo
    {
        return $this->belongsTo(EmiSchedule::class);
    }
}
