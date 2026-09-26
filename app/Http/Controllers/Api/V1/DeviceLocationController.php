<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReportDeviceLocationRequest;
use App\Http\Resources\Api\V1\DeviceLocationResource;
use App\Models\Device;
use App\Services\AuditService;
use App\Services\DeviceCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeviceLocationController extends Controller
{
    public function __construct(private readonly AuditService $audit, private readonly DeviceCommandService $commands) {}

    public function report(ReportDeviceLocationRequest $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        if (! $device->location_tracking_enabled || ! $device->location_consent_given_at || $device->location_consent_withdrawn_at) {
            throw ValidationException::withMessages(['location' => [['code' => 'location_consent_required', 'message' => 'Location reporting is not enabled with active consent.']]]);
        }
        $location = $device->locations()->create(['company_id' => $device->company_id, 'latitude' => $request->validated('latitude'), 'longitude' => $request->validated('longitude'), 'accuracy_meters' => $request->validated('accuracy'), 'captured_at' => $request->validated('captured_at'), 'received_at' => now(), 'source' => $request->validated('source'), 'tracking_mode' => $device->location_tracking_mode]);

        return (new DeviceLocationResource($location))->response()->setStatusCode(201);
    }

    public function latest(Device $device): JsonResponse
    {
        $location = null;
        if ($device->location_tracking_enabled && $device->location_consent_given_at && ! $device->location_consent_withdrawn_at) {
            $location = $device->locations()
                ->where('captured_at', '>=', $device->location_consent_given_at)
                ->latest('captured_at')
                ->first();
        }

        return response()->json(['data' => ['settings' => $this->settings($device), 'last_location' => $location ? new DeviceLocationResource($location) : null]]);
    }

    public function index(Request $request, Device $device): AnonymousResourceCollection
    {
        $v = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $q = $device->locations()
            ->when($device->location_consent_given_at, fn ($q, $since) => $q->where('captured_at', '>=', $since))
            ->latest('captured_at')
            ->when($v['date_from'] ?? null, fn ($q, $x) => $q->where('captured_at', '>=', $x))
            ->when($v['date_to'] ?? null, fn ($q, $x) => $q->where('captured_at', '<=', $x));

        if (! $device->location_tracking_enabled || ! $device->location_consent_given_at || $device->location_consent_withdrawn_at) {
            $q->whereRaw('1 = 0');
        }

        return DeviceLocationResource::collection($q->paginate($v['per_page'] ?? 20));
    }

    public function settingsUpdate(Request $request, Device $device): JsonResponse
    {
        $v = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'location_feature_enabled' => ['sometimes', 'boolean'],
            'requested_mode' => ['required', Rule::in(['disabled', 'foreground_only', 'background_allowed'])],
            'consent_confirmed' => ['required', 'boolean'],
            'remarks' => ['required', 'string', 'max:1000']
        ]);
        $enabled = (bool) ($v['enabled'] ?? $v['location_feature_enabled'] ?? false);
        if ($enabled && (! $v['consent_confirmed'] || $v['requested_mode'] === 'disabled')) {
            throw ValidationException::withMessages(['consent_confirmed' => ['Explicit location consent and an enabled mode are required.']]);
        }
        $old = $this->settings($device);
        $device->update(['location_tracking_enabled' => $enabled, 'location_tracking_mode' => $enabled ? $v['requested_mode'] : 'disabled', 'location_consent_given_at' => $enabled ? now() : $device->location_consent_given_at, 'location_consent_withdrawn_at' => $enabled ? null : now(), 'updated_by' => $request->user()->id]);
        $action = $enabled ? 'device.location_consent_enabled' : 'device.location_consent_withdrawn';
        $this->audit->record($action, $device, $old, $this->settings($device), $v['remarks']);
        if ($device->enrollment_status === 'enrolled') {
            try {
                $this->commands->queue($device, 'policy_sync', ['remarks' => 'Location consent/settings changed'], $request->user(), 'manual');
            } catch (ValidationException) {
                // Settings remain authoritative; periodic heartbeat reconciles unsupported/offline agents.
            }
        }

        return response()->json(['data' => $this->settings($device)]);
    }

    private function settings(Device $device): array
    {
        return ['enabled' => $device->location_tracking_enabled, 'tracking_mode' => $device->location_tracking_mode, 'permission_state' => $device->location_permission_state, 'consent_given_at' => $device->location_consent_given_at?->toISOString(), 'consent_withdrawn_at' => $device->location_consent_withdrawn_at?->toISOString()];
    }
}
