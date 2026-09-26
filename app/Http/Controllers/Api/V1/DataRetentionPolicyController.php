<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateRetentionPolicyRequest;
use App\Http\Resources\Api\V1\DataRetentionPolicyResource;
use App\Models\DataRetentionPolicy;
use App\Services\AuditService;
use App\Services\DataRetentionPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DataRetentionPolicyController extends Controller
{
    public function __construct(
        private readonly DataRetentionPolicyService $retention,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'data_type' => ['nullable', Rule::in(DataRetentionPolicy::DATA_TYPES)],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $company = app(\App\Support\TenantContext::class)->company() ?? $request->user()->company;
        if ($company) {
            $this->retention->ensureDefaults($company);
        }

        $policies = DataRetentionPolicy::query()
            ->when($validated['data_type'] ?? null, fn ($query, string $dataType) => $query->where('data_type', $dataType))
            ->when(array_key_exists('is_active', $validated), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('data_type')
            ->paginate($validated['per_page'] ?? 50)
            ->withQueryString();

        return DataRetentionPolicyResource::collection($policies);
    }

    public function show(DataRetentionPolicy $policy): DataRetentionPolicyResource
    {
        return new DataRetentionPolicyResource($policy);
    }

    public function update(UpdateRetentionPolicyRequest $request, DataRetentionPolicy $policy): DataRetentionPolicyResource
    {
        $previous = $policy->only(['data_type', 'retention_period_days', 'action', 'is_active']);
        $policy->update($request->validated());

        $this->audit->record('retention.policy_updated', $policy, $request->user(), [
            'data_type' => $policy->data_type,
            'previous' => $previous,
            'current' => $policy->only(['retention_period_days', 'action', 'is_active']),
        ]);

        return (new DataRetentionPolicyResource($policy))->additional(['message' => 'Retention policy updated.']);
    }

    /** Applies all active policies for the policy's company immediately (idempotent). */
    public function apply(Request $request, DataRetentionPolicy $policy): JsonResponse
    {
        $result = $this->retention->apply($policy->company);

        return response()->json([
            'data' => $result,
            'message' => sprintf(
                'Retention applied: %d policy row(s) processed, %d record(s) deleted, %d anonymized, %d kept.',
                $result['processed'],
                $result['deleted'],
                $result['anonymized'],
                $result['kept'],
            ),
        ]);
    }
}