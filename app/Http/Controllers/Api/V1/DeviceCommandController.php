<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateDeviceCommandRequest;
use App\Http\Requests\Api\V1\DeviceCommandResultRequest;
use App\Http\Resources\Api\V1\DeviceCommandResource;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\DeviceCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceCommandController extends Controller
{
    public function __construct(private readonly DeviceCommandService $commands) {}

    public function index(Request $request, Device $device)
    {
        $data = $request->validate(['command_type' => ['nullable', Rule::in(DeviceCommand::TYPES)], 'status' => ['nullable', Rule::in(DeviceCommand::STATUSES)],
            'requested_by' => ['nullable', 'integer'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = $device->commands()->with('requester')->latest('requested_at');
        foreach (['command_type', 'status', 'requested_by'] as $filter) {
            if (isset($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }
        if (isset($data['date_from'])) {
            $query->whereDate('requested_at', '>=', $data['date_from']);
        }
        if (isset($data['date_to'])) {
            $query->whereDate('requested_at', '<=', $data['date_to']);
        }

        return DeviceCommandResource::collection($query->paginate($data['per_page'] ?? 20));
    }

    public function show(DeviceCommand $command): DeviceCommandResource
    {
        return new DeviceCommandResource($command->load(['device', 'requester']));
    }

    public function warning(CreateDeviceCommandRequest $request, Device $device): JsonResponse
    {
        return $this->create($request, $device, 'show_warning');
    }

    public function partialLock(CreateDeviceCommandRequest $request, Device $device): JsonResponse
    {
        return $this->create($request, $device, 'partial_lock');
    }

    public function fullLock(CreateDeviceCommandRequest $request, Device $device): JsonResponse
    {
        return $this->create($request, $device, 'full_lock');
    }

    public function unlock(CreateDeviceCommandRequest $request, Device $device): JsonResponse
    {
        return $this->create($request, $device, 'unlock');
    }

    public function policySync(CreateDeviceCommandRequest $request, Device $device): JsonResponse
    {
        return $this->create($request, $device, 'policy_sync');
    }

    public function refreshStatus(CreateDeviceCommandRequest $request, Device $device): JsonResponse
    {
        return $this->create($request, $device, 'refresh_status');
    }

    public function cancel(Request $request, DeviceCommand $command): DeviceCommandResource
    {
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']]);

        return new DeviceCommandResource($this->commands->cancel($command, $request->user(), $data['remarks'] ?? null));
    }

    public function pull(Request $request): JsonResponse
    {
        $command = $this->commands->pull($request->attributes->get('device'));

        return response()->json(['data' => $command ? new DeviceCommandResource($command) : null]);
    }

    public function received(Request $request, DeviceCommand $command): DeviceCommandResource
    {
        return new DeviceCommandResource($this->commands->markReceived($request->attributes->get('device'), $command));
    }

    public function acknowledge(Request $request, DeviceCommand $command): DeviceCommandResource
    {
        return new DeviceCommandResource($this->commands->acknowledge($request->attributes->get('device'), $command));
    }

    public function result(DeviceCommandResultRequest $request, DeviceCommand $command): DeviceCommandResource
    {
        return new DeviceCommandResource($this->commands->result($request->attributes->get('device'), $command, $request->validated()));
    }

    private function create(CreateDeviceCommandRequest $request, Device $device, string $type): JsonResponse
    {
        $company = app(\App\Support\TenantContext::class)->company();
        if (in_array($type, ['show_warning', 'partial_lock', 'full_lock'], true) && $company?->expires_at && $company->expires_at->isPast()) {
            return response()->json(['message' => 'Your company subscription has expired. Please recharge your plan to execute device lock/warning actions.'], 403);
        }

        $result = $this->commands->queue($device, $type, $request->validated(), $request->user());

        return (new DeviceCommandResource($result['command']->load('requester')))->response()->setStatusCode($result['created'] ? 201 : 200);
    }
}
