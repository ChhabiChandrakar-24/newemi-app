<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'duration_months',
        'duration_type',
        'duration_value',
        'device_limit',
        'price',
        'currency',
        'features',
        'is_trial',
        'is_active',
        'is_popular',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'duration_months' => 'integer',
            'duration_value' => 'integer',
            'device_limit' => 'integer',
            'price' => 'decimal:2',
            'features' => 'array',
            'is_trial' => 'boolean',
            'is_active' => 'boolean',
            'is_popular' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function recharges(): HasMany
    {
        return $this->hasMany(CompanyRecharge::class, 'plan_id');
    }
}
