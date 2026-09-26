<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDeviceRequest;
use App\Http\Requests\Api\V1\UpdateDeviceRequest;
use App\Http\Requests\Api\V1\UpdateDeviceStatusRequest;
use App\Http\Resources\Api\V1\DeviceResource;
use App\Models\Customer;
use App\Models\Device;
use App\Models\EmiAccount;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class DeviceController extends Controller
{
    public function __construct(private readonly DeviceService $devices) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'emi_account_id' => ['nullable', 'integer', 'exists:emi_accounts,id'], 'enrollment_status' => ['nullable', 'in:pending,enrolled,suspended,failed,released'],
            'control_status' => ['nullable', 'in:active,warning,partial_lock,full_lock,unlocked,closed,released'],
            'connectivity_status' => ['nullable', 'in:online,offline,unknown'], 'compliance_status' => ['nullable', 'in:unknown,compliant,non_compliant,attention_required'],
            'management_mode' => ['nullable', 'in:unmanaged,device_owner,fully_managed,dedicated,unknown'], 'offline_only' => ['nullable', 'boolean'],
            'last_seen_from' => ['nullable', 'date'], 'last_seen_to' => ['nullable', 'date', 'after_or_equal:last_seen_from'],
            'sort' => ['nullable', 'in:created_at,last_seen_at,device_code,brand,model'], 'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $devices = Device::query()->with(['customer', 'emiAccount'])
            ->when($v['search'] ?? null, function ($q, string $s): void {
                $q->where(function ($q) use ($s): void {
                    $q->where('device_code', 'like', "%{$s}%")->orWhere('invoice_number', 'like', "%{$s}%")
                        ->orWhere('brand', 'like', "%{$s}%")->orWhere('model', 'like', "%{$s}%")->orWhere('imei1', 'like', "%{$s}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$s}%")->orWhere('mobile_number', 'like', "%{$s}%"));
                });
            })
            ->when($v['customer_id'] ?? null, fn ($q, $x) => $q->where('customer_id', $x))->when($v['emi_account_id'] ?? null, fn ($q, $x) => $q->where('emi_account_id', $x))
            ->when($v['enrollment_status'] ?? null, fn ($q, $x) => $q->where('enrollment_status', $x))->when($v['control_status'] ?? null, fn ($q, $x) => $q->where('control_status', $x))
            ->when($v['connectivity_status'] ?? null, fn ($q, $x) => $q->where('connectivity_status', $x))->when($v['compliance_status'] ?? null, fn ($q, $x) => $q->where('compliance_status', $x))
            ->when($v['management_mode'] ?? null, fn ($q, $x) => $q->where('management_mode', $x))->when($request->boolean('offline_only'), fn ($q) => $q->where('connectivity_status', 'offline'))
            ->when($v['last_seen_from'] ?? null, fn ($q, $x) => $q->where('last_seen_at', '>=', $x))->when($v['last_seen_to'] ?? null, fn ($q, $x) => $q->where('last_seen_at', '<=', $x))
            ->orderBy($v['sort'] ?? 'created_at', $v['direction'] ?? 'desc')->paginate($v['per_page'] ?? 15)->withQueryString();

        return DeviceResource::collection($devices);
    }

    public function show(Device $device): DeviceResource
    {
        return new DeviceResource($device->load(['customer', 'emiAccount']));
    }

    public function store(StoreDeviceRequest $request): JsonResponse
    {
        return (new DeviceResource($this->devices->register($request->validated(), $request->user())))->additional(['message' => 'Device registered successfully.'])->response()->setStatusCode(201);
    }

    public function update(UpdateDeviceRequest $request, Device $device): DeviceResource
    {
        return (new DeviceResource($this->devices->update($device, $request->validated(), $request->user())))->additional(['message' => 'Device updated successfully.']);
    }

    public function updateStatus(UpdateDeviceStatusRequest $request, Device $device): DeviceResource
    {
        return (new DeviceResource($this->devices->changeStatus($device, $request->validated(), $request->user())))->additional(['message' => 'Device status updated; no device command was issued.']);
    }

    public function destroy(Request $request, Device $device): Response
    {
        $this->devices->delete($device, $request->user());

        return response()->noContent();
    }

    public function forCustomer(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $p = $request->integer('per_page', 15);

        return DeviceResource::collection($customer->devices()->with(['customer', 'emiAccount'])->paginate(min(max($p, 1), 100)));
    }

    public function forAccount(Request $request, EmiAccount $emiAccount): AnonymousResourceCollection
    {
        $p = $request->integer('per_page', 15);

        return DeviceResource::collection($emiAccount->devices()->with(['customer', 'emiAccount'])->paginate(min(max($p, 1), 100)));
    }

    public function summary(Request $request, Device $device): JsonResponse
    {
        $device->load(['customer', 'emiAccount', 'company']);

        return response()->json(['data' => [
            'device' => (new DeviceResource($device))->resolve(request()),
            'events' => ['total' => $device->events()->count(), 'unacknowledged_high_critical' => $device->events()->whereIn('severity', ['high', 'critical'])->whereNull('acknowledged_at')->count()],
            'delivery' => ['fcm_registered' => $device->pushTokens()->where('is_active', true)->exists(), 'last_successful_push_at' => $device->pushTokens()->max('last_success_at')],
            'location' => [
                'enabled' => (bool) $device->location_tracking_enabled,
                'tracking_mode' => $device->location_tracking_mode ?: 'disabled',
                'permission_state' => $device->location_permission_state ?: 'not_requested',
                'last_location_at' => ($request->user()->can('devices.location.view') && $device->location_tracking_enabled && $device->location_consent_given_at && ! $device->location_consent_withdrawn_at)
                    ? $device->locations()->where('captured_at', '>=', $device->location_consent_given_at)->max('captured_at')
                    : null,
                'settings' => [
                    'enabled' => (bool) $device->location_tracking_enabled,
                    'tracking_mode' => $device->location_tracking_mode ?: 'disabled',
                    'permission_state' => $device->location_permission_state ?: 'not_requested',
                    'consent_given_at' => $device->location_consent_given_at?->toISOString(),
                ],
                'last_location' => ($request->user()->can('devices.location.view') && $device->location_tracking_enabled && $device->location_consent_given_at && ! $device->location_consent_withdrawn_at)
                    ? $device->locations()->where('captured_at', '>=', $device->location_consent_given_at)->latest('captured_at')->first()
                    : null,
            ],
        ]]);
    }

    public function release(Request $request, Device $device): DeviceResource
    {
        return (new DeviceResource($this->devices->release($device, $request->user())))->additional(['message' => 'Backend release prepared; no device-side action was executed.']);
    }

    public function status(Request $request, Device $device): JsonResponse
    {
        $device->load(['customer', 'emiAccount', 'company']);

        return response()->json([
            'data' => [
                'id' => $device->id,
                'device_code' => $device->device_code,
                'brand' => $device->brand,
                'model' => $device->model,
                'imei1' => $device->imei1,
                'imei2' => $device->imei2,
                'serial_number' => $device->serial_number,
                'enrollment_status' => $device->enrollment_status,
                'management_status' => $device->management_status ?? ($device->enrollment_status === 'released' ? 'RELEASED' : 'MANAGED'),
                'control_status' => $device->control_status,
                'connection_status' => $device->connection_status ?? ($device->connectivity_status === 'online' ? 'ONLINE' : ($device->connectivity_status === 'offline' ? 'OFFLINE' : 'UNKNOWN')),
                'connectivity_status' => $device->connectivity_status,
                'device_lock_status' => $device->device_lock_status ?? (in_array($device->control_status, ['full_lock', 'locked']) ? 'LOCKED' : (in_array($device->control_status, ['partial_lock', 'lock_pending']) ? 'LOCK_PENDING' : 'UNLOCKED')),
                'compliance_status' => $device->compliance_status,
                'emi_status' => $device->emiAccount?->emi_status ?? $device->emiAccount?->status,
                'last_seen_at' => $device->last_seen_at?->toISOString(),
                'last_heartbeat_at' => $device->last_heartbeat_at?->toISOString(),
                'is_online' => $device->connection_status === 'ONLINE' || $device->connectivity_status === 'online',
                'released_at' => $device->released_at?->toISOString(),
                'shop' => [
                    'id' => $device->company_id,
                    'name' => $device->company?->name,
                    'support_phone' => $device->company?->support_phone ?? $device->company?->phone,
                    'support_email' => $device->company?->support_email ?? $device->company?->email,
                ],
            ],
        ]);
    }

    public function restrictions(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $v['status'] ?? 'all_restricted';

        $query = Device::query()
            ->with(['customer', 'emiAccount', 'company']);

        if ($status === 'full_locked') {
            $query->where(function ($q): void {
                $q->whereIn('control_status', ['full_locked', 'locked'])
                    ->orWhereIn('desired_control_status', ['full_locked', 'locked']);
            });
        } elseif ($status === 'partial_locked') {
            $query->where(function ($q): void {
                $q->whereIn('control_status', ['partial_locked', 'restricted'])
                    ->orWhereIn('desired_control_status', ['partial_locked', 'restricted']);
            });
        } elseif ($status === 'warning') {
            $query->where(function ($q): void {
                $q->where('control_status', 'warning')
                    ->orWhere('desired_control_status', 'warning');
            });
        } elseif ($status === 'policy') {
            $query->whereNotNull('lock_policy_id');
        } elseif ($status === 'all_devices') {
            // No restriction filter, show all devices
        } else {
            // Default: all restricted / controlled
            $query->where(function ($q): void {
                $q->whereIn('control_status', ['partial_locked', 'full_locked', 'warning', 'locked', 'restricted'])
                    ->orWhereIn('desired_control_status', ['partial_locked', 'full_locked', 'warning', 'locked', 'restricted'])
                    ->orWhereNotNull('lock_policy_id');
            });
        }

        $query->when($v['search'] ?? null, function ($q, string $s): void {
            $q->where(function ($sub) use ($s): void {
                $sub->where('device_code', 'like', "%{$s}%")
                    ->orWhere('brand', 'like', "%{$s}%")
                    ->orWhere('model', 'like', "%{$s}%")
                    ->orWhere('imei1', 'like', "%{$s}%")
                    ->orWhere('control_status', 'like', "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$s}%")->orWhere('mobile_number', 'like', "%{$s}%"));
            });
        })->latest('updated_at');

        $stats = [
            'total_controlled' => Device::query()->where(function ($q): void {
                $q->whereIn('control_status', ['partial_locked', 'full_locked', 'warning', 'locked', 'restricted'])
                    ->orWhereIn('desired_control_status', ['partial_locked', 'full_locked', 'warning', 'locked', 'restricted'])
                    ->orWhereNotNull('lock_policy_id');
            })->count(),
            'full_locked' => Device::query()->where(fn ($q) => $q->whereIn('control_status', ['full_locked', 'locked'])->orWhereIn('desired_control_status', ['full_locked', 'locked']))->count(),
            'partial_locked' => Device::query()->where(fn ($q) => $q->whereIn('control_status', ['partial_locked', 'restricted'])->orWhereIn('desired_control_status', ['partial_locked', 'restricted']))->count(),
            'warning' => Device::query()->where(fn ($q) => $q->where('control_status', 'warning')->orWhere('desired_control_status', 'warning'))->count(),
            'active_policies' => Device::query()->whereNotNull('lock_policy_id')->count(),
            'total_devices' => Device::count(),
        ];

        return DeviceResource::collection($query->paginate($v['per_page'] ?? 25)->withQueryString())->additional([
            'meta' => [
                'stats' => $stats,
            ],
        ]);
    }

    public function triggerEscalationCycle(Request $request, Device $device, \App\Services\AutomaticDeviceCommandService $engine): JsonResponse
    {
        $force = filter_var($request->input('force', true), FILTER_VALIDATE_BOOLEAN);
        $result = $engine->evaluate($device, $request->user(), forceInterval: $force);

        return response()->json([
            'message' => 'Escalation cycle evaluated successfully.',
            'data' => $result,
        ]);
    }
}

