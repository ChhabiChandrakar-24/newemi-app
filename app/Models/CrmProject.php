<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmProject extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'lead_id',
        'project_code',
        'client_name',
        'client_phone',
        'client_email',
        'title',
        'project_type',
        'description',
        'total_cost',
        'paid_amount',
        'balance_amount',
        'status',
        'start_date',
        'delivery_deadline',
        'assigned_to',
        'milestones',
        'created_by',
        'updated_by',
    ];

    protected $appends = ['name', 'total_budget', 'advance_paid', 'expected_delivery_date', 'notes'];

    protected function casts(): array
    {
        return [
            'total_cost' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'start_date' => 'date',
            'delivery_deadline' => 'date',
            'milestones' => 'array',
        ];
    }

    public function getNameAttribute(): string
    {
        return $this->title ?? '';
    }

    public function getTotalBudgetAttribute(): float
    {
        return (float) ($this->total_cost ?? 0);
    }

    public function getAdvancePaidAttribute(): float
    {
        return (float) ($this->paid_amount ?? 0);
    }

    public function getExpectedDeliveryDateAttribute(): ?string
    {
        return $this->delivery_deadline?->format('Y-m-d');
    }

    public function getNotesAttribute(): ?string
    {
        return $this->description;
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function assignedDeveloper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
