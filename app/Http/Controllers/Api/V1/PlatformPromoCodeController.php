<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformPromoCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PromoCode::query()->with(['creator:id,name,email'])->withCount('usages');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active') && $request->input('is_active') !== null && $request->input('is_active') !== '') {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $promoCodes = $query->latest('id')->paginate($perPage);

        return response()->json($promoCodes);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:promo_codes,code'],
            'title' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'discount_type' => ['required', 'string', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_company' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'allowed_plans' => ['nullable', 'array'],
            'allowed_plans.*' => ['integer', 'exists:subscription_plans,id'],
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['created_by'] = $request->user()?->id;

        if ($validated['discount_type'] === 'percentage' && $validated['discount_value'] > 100) {
            return response()->json([
                'message' => 'Percentage discount cannot exceed 100%.',
                'errors' => ['discount_value' => ['Percentage discount cannot exceed 100%.']],
            ], 422);
        }

        $promoCode = PromoCode::create($validated);

        return response()->json([
            'message' => "Promo Code '{$promoCode->code}' created successfully!",
            'promo_code' => $promoCode->load(['creator:id,name,email']),
        ], 201);
    }

    public function show(PromoCode $promoCode): JsonResponse
    {
        return response()->json([
            'promo_code' => $promoCode->load(['creator:id,name,email']),
            'recent_usages' => $promoCode->usages()->with(['company:id,name,company_code', 'user:id,name,email'])->latest('id')->limit(20)->get(),
        ]);
    }

    public function update(Request $request, PromoCode $promoCode): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('promo_codes', 'code')->ignore($promoCode->id)],
            'title' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'discount_type' => ['required', 'string', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_company' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'allowed_plans' => ['nullable', 'array'],
            'allowed_plans.*' => ['integer', 'exists:subscription_plans,id'],
        ]);

        $validated['code'] = strtoupper(trim($validated['code']));
        if (isset($validated['is_active'])) {
            $validated['is_active'] = $request->boolean('is_active');
        }

        if ($validated['discount_type'] === 'percentage' && $validated['discount_value'] > 100) {
            return response()->json([
                'message' => 'Percentage discount cannot exceed 100%.',
                'errors' => ['discount_value' => ['Percentage discount cannot exceed 100%.']],
            ], 422);
        }

        $promoCode->update($validated);

        return response()->json([
            'message' => "Promo Code '{$promoCode->code}' updated successfully!",
            'promo_code' => $promoCode->fresh()->load(['creator:id,name,email']),
        ]);
    }

    public function destroy(PromoCode $promoCode): JsonResponse
    {
        $code = $promoCode->code;
        $promoCode->delete();

        return response()->json([
            'message' => "Promo Code '{$code}' deleted successfully.",
        ]);
    }

    public function toggle(PromoCode $promoCode): JsonResponse
    {
        $promoCode->update(['is_active' => ! $promoCode->is_active]);

        return response()->json([
            'message' => "Promo Code '{$promoCode->code}' is now " . ($promoCode->is_active ? 'Active' : 'Disabled') . ".",
            'promo_code' => $promoCode,
        ]);
    }
}
