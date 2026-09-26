<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class SubscriptionPlanService
{
    public function getActivePlans(): Collection
    {
        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();
    }

    public function paginatePlans(int $perPage = 20, ?string $search = null, ?bool $activeOnly = null): LengthAwarePaginator
    {
        $query = SubscriptionPlan::query();

        if ($search) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($activeOnly !== null) {
            $query->where('is_active', $activeOnly);
        }

        return $query->orderBy('sort_order')
            ->orderBy('price')
            ->paginate($perPage);
    }

    public function calculateCustomQuote(int $months, int $deviceCount): array
    {
        $months = max(1, min(36, $months));
        $deviceCount = max(5, min(5000, $deviceCount));

        // Base rate: ₹25 per device per month
        $basePerDevicePerMonth = 25.00;
        $rawPrice = $deviceCount * $months * $basePerDevicePerMonth;

        // Discounts for longer commitments
        $discountPercentage = match (true) {
            $months >= 12 => 25,
            $months >= 6 => 15,
            $months >= 3 => 8,
            default => 0,
        };

        $discountAmount = round(($rawPrice * $discountPercentage) / 100, 2);
        $finalPrice = max(499.00, round($rawPrice - $discountAmount, 2));

        return [
            'duration_months' => $months,
            'device_limit' => $deviceCount,
            'rate_per_device_month' => $basePerDevicePerMonth,
            'subtotal' => $rawPrice,
            'discount_percentage' => $discountPercentage,
            'discount_amount' => $discountAmount,
            'total_price' => $finalPrice,
            'currency' => 'INR',
        ];
    }

    public function create(array $data): SubscriptionPlan
    {
        return SubscriptionPlan::create($data);
    }

    public function update(SubscriptionPlan $plan, array $data): SubscriptionPlan
    {
        $plan->update($data);

        return $plan->fresh();
    }

    public function delete(SubscriptionPlan $plan): void
    {
        $plan->delete();
    }
}
