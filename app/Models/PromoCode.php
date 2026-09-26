<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PromoCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'title',
        'description',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'min_order_amount',
        'usage_limit',
        'times_used',
        'usage_limit_per_company',
        'starts_at',
        'expires_at',
        'is_active',
        'allowed_plans',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'usage_limit' => 'integer',
            'times_used' => 'integer',
            'usage_limit_per_company' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'allowed_plans' => 'array',
        ];
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PromoCodeUsage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Validate and calculate discount for an amount.
     *
     * @throws ValidationException
     */
    public function calculateDiscount(float $amount, ?int $planId = null, ?int $companyId = null): array
    {
        if (! $this->is_active) {
            throw ValidationException::withMessages([
                'promo_code' => ['This promo code is currently disabled or inactive.'],
            ]);
        }

        $now = now();

        if ($this->starts_at && $this->starts_at->isFuture()) {
            throw ValidationException::withMessages([
                'promo_code' => ["This promo code will become active on {$this->starts_at->format('d M Y')}."],
            ]);
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'promo_code' => ['This promo code has expired.'],
            ]);
        }

        if ($this->usage_limit !== null && $this->times_used >= $this->usage_limit) {
            throw ValidationException::withMessages([
                'promo_code' => ['This promo code has reached its maximum total usage limit.'],
            ]);
        }

        if ($companyId && $this->usage_limit_per_company > 0) {
            $companyUsageCount = $this->usages()->where('company_id', $companyId)->count();
            if ($companyUsageCount >= $this->usage_limit_per_company) {
                throw ValidationException::withMessages([
                    'promo_code' => ["You have already used promo code {$this->code} the maximum allowed times ({$this->usage_limit_per_company})."],
                ]);
            }
        }

        if ($this->min_order_amount > 0 && $amount < (float) $this->min_order_amount) {
            throw ValidationException::withMessages([
                'promo_code' => ["This promo code requires a minimum plan amount of ₹" . number_format((float) $this->min_order_amount, 2) . "."],
            ]);
        }

        if (! empty($this->allowed_plans) && is_array($this->allowed_plans)) {
            if (! $planId || ! in_array($planId, array_map('intval', $this->allowed_plans), true)) {
                throw ValidationException::withMessages([
                    'promo_code' => ['This promo code is not applicable to the selected subscription plan.'],
                ]);
            }
        }

        $discount = 0.0;
        if ($this->discount_type === 'percentage') {
            $discount = ($amount * (float) $this->discount_value) / 100.0;
            if ($this->max_discount_amount !== null && (float) $this->max_discount_amount > 0) {
                $discount = min($discount, (float) $this->max_discount_amount);
            }
        } else {
            // Fixed discount
            $discount = min((float) $this->discount_value, $amount);
        }

        $discount = round($discount, 2);
        $finalAmount = max(0.0, round($amount - $discount, 2));

        return [
            'valid' => true,
            'promo_id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'discount_type' => $this->discount_type,
            'discount_value' => (float) $this->discount_value,
            'original_amount' => round($amount, 2),
            'discount_amount' => $discount,
            'final_amount' => $finalAmount,
            'message' => "Promo code '{$this->code}' applied! You save ₹" . number_format($discount, 2) . ".",
        ];
    }
}
