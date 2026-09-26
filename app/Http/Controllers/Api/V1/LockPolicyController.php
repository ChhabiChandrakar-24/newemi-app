<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLockPolicyRequest;
use App\Http\Requests\Api\V1\UpdateLockPolicyRequest;
use App\Http\Resources\Api\V1\DeviceResource;
use App\Http\Resources\Api\V1\LockPolicyResource;
use App\Models\Device;
use App\Models\LockPolicy;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LockPolicyController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $data = $request->validate(['active' => ['nullable', 'boolean'], 'archived' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return LockPolicyResource::collection(LockPolicy::query()->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed())->when(isset($data['active']), fn ($q) => $q->where('is_active', $data['active']))->latest()->paginate($data['per_page'] ?? 20));
    }

    public function show(LockPolicy $policy): LockPolicyResource
    {
        return new LockPolicyResource($policy);
    }

    public function store(StoreLockPolicyRequest $request): LockPolicyResource
    {
        $policy = DB::transaction(function () use ($request) {
            $data = $request->validated();
            if ($data['is_default'] ?? false) {
                LockPolicy::query()->update(['is_default' => false]);
            }
            $policy = LockPolicy::create([...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $this->audit->record('lock_policy.created', $policy, null, $policy->toArray());

            return $policy;
        });

        return new LockPolicyResource($policy);
    }

    public function update(UpdateLockPolicyRequest $request, LockPolicy $policy): LockPolicyResource
    {
        return new LockPolicyResource(DB::transaction(function () use ($request, $policy) {
            $old = $policy->toArray();
            $data = $request->validated();
            if ($data['is_default'] ?? false) {
                LockPolicy::query()->whereKeyNot($policy->id)->update(['is_default' => false]);
            }
            $policy->update([...$data, 'updated_by' => $request->user()->id]);
            $this->audit->record('lock_policy.updated', $policy, $old, $policy->fresh()->toArray());

            return $policy->fresh();
        }));
    }

    public function status(Request $request, LockPolicy $policy): LockPolicyResource
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $old = $policy->is_active;
        $policy->update(['is_active' => $data['is_active'], 'updated_by' => $request->user()->id]);
        $this->audit->record('lock_policy.status_changed', $policy, ['is_active' => $old], ['is_active' => $policy->is_active]);

        return new LockPolicyResource($policy);
    }

    public function assign(Request $request, Device $device): DeviceResource
    {
        $data = $request->validate(['lock_policy_id' => ['nullable', 'exists:lock_policies,id'], 'policy_automation_paused' => ['sometimes', 'boolean']]);
        $old = ['lock_policy_id' => $device->lock_policy_id, 'policy_automation_paused' => $device->policy_automation_paused];
        $device->update([...$data, 'updated_by' => $request->user()->id]);
        $this->audit->record('device.lock_policy_assigned', $device, $old, $data);

        return new DeviceResource($device->fresh()->load(['customer', 'emiAccount']));
    }

    public function duplicate(Request $request, LockPolicy $policy): LockPolicyResource
    {
        $copy = $policy->replicate(['is_default', 'created_by', 'updated_by']);
        $copy->name = $request->validate(['name' => ['required', 'string', 'max:150']])['name'];
        $copy->is_default = false;
        $copy->created_by = $request->user()->id;
        $copy->updated_by = $request->user()->id;
        $copy->save();
        $this->audit->record('lock_policy.duplicated', $copy, null, $copy->toArray());

        return new LockPolicyResource($copy);
    }

    public function destroy(LockPolicy $policy): JsonResponse
    {
        abort_if($policy->is_default || $policy->devices()->exists(), 422, 'Default or assigned policies cannot be archived.');
        $policy->delete();
        $this->audit->record('lock_policy.archived', $policy, $policy->toArray(), null);

        return response()->json(['message' => 'Lock policy archived.']);
    }

    public function restore(int $policy): LockPolicyResource
    {
        $model = LockPolicy::onlyTrashed()->findOrFail($policy);
        $model->restore();
        $this->audit->record('lock_policy.restored', $model, null, $model->toArray());

        return new LockPolicyResource($model);
    }
}
