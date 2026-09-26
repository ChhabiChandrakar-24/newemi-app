<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LockPolicy extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'is_default', 'is_active', 'warning_after_overdue_days',
        'partial_lock_after_overdue_days', 'full_lock_after_overdue_days',
        'unlock_on_payment_clearance', 'grace_period_override', 'offline_behavior',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'unlock_on_payment_clearance' => 'boolean',
        ];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
