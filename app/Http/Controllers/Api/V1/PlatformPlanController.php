<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformPlanController extends Controller
{
    public function __construct(private readonly SubscriptionPlanService $planService) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('per_page', 20), 100);
        $search = $request->string('search')->trim()->value() ?: null;
        $activeOnly = $request->has('active_only') ? $request->boolean('active_only') : null;

        $plans = $this->planService->paginatePlans($perPage, $search, $activeOnly);

        return response()->json($plans);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:64', 'unique:subscription_plans,code'],
            'description' => ['nullable', 'string', 'max:500'],
            'duration_type' => ['nullable', 'string', Rule::in(['hours', 'days', 'months'])],
            'duration_value' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'device_limit' => ['required', 'integer', 'min:1', 'max:10000'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'features' => ['nullable', 'array'],
            'is_trial' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_popular' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $durationType = $validated['duration_type'] ?? 'months';
        $durationValue = (int) ($validated['duration_value'] ?? $validated['duration_months'] ?? 1);
        $durationMonths = $durationType === 'months'
            ? $durationValue
            : max(1, (int) ceil(($durationType === 'days' ? $durationValue : $durationValue / 24) / 30));

        $validated['duration_type'] = $durationType;
        $validated['duration_value'] = $durationValue;
        $validated['duration_months'] = $durationMonths;
        $validated['is_trial'] = (bool) ($validated['is_trial'] ?? false);

        $plan = $this->planService->create($validated);

        return response()->json([
            'message' => 'Subscription plan created successfully.',
            'plan' => $plan,
        ], 201);
    }

    public function show(SubscriptionPlan $plan): JsonResponse
    {
        return response()->json(['plan' => $plan]);
    }

    public function update(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'code' => ['sometimes', 'required', 'string', 'max:64', Rule::unique('subscription_plans', 'code')->ignore($plan->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'duration_type' => ['nullable', 'string', Rule::in(['hours', 'days', 'months'])],
            'duration_value' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'device_limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:10000'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'features' => ['nullable', 'array'],
            'is_trial' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_popular' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        if (isset($validated['duration_type']) || isset($validated['duration_value'])) {
            $durationType = $validated['duration_type'] ?? $plan->duration_type ?? 'months';
            $durationValue = (int) ($validated['duration_value'] ?? $plan->duration_value ?? 1);
            $validated['duration_type'] = $durationType;
            $validated['duration_value'] = $durationValue;
            $validated['duration_months'] = $durationType === 'months'
                ? $durationValue
                : max(1, (int) ceil(($durationType === 'days' ? $durationValue : $durationValue / 24) / 30));
        }

        $updated = $this->planService->update($plan, $validated);

        return response()->json([
            'message' => 'Subscription plan updated successfully.',
            'plan' => $updated,
        ]);
    }

    public function destroy(SubscriptionPlan $plan): JsonResponse
    {
        $this->planService->delete($plan);

        return response()->json(['message' => 'Subscription plan deleted successfully.']);
    }
}
