<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DeviceEventResource;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Services\AuditService;
use App\Services\DeviceEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DeviceEventController extends Controller
{
    public function __construct(private readonly DeviceEventService $events) {}

    /** Device-facing: agent pushes a bounded event (sim change, tamper, permissions, compliance). */
    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_type' => ['required', Rule::in(config('devices.event_types'))],
            'severity' => ['required', 'in:info,warning,high,critical'],
            'payload' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $payload = $validated['payload'] ?? [];
        if (isset($validated['occurred_at'])) {
            $payload['occurred_at'] = $validated['occurred_at'];
        }

        $event = $this->events->record(
            $request->attributes->get('device'),
            $validated['event_type'],
            $validated['severity'],
            $payload === [] ? null : $payload,
        );

        return (new DeviceEventResource($event))
            ->additional(['message' => 'Device event recorded.'])
            ->response()
            ->setStatusCode(201);
    }

    public function index(Request $request, Device $device): AnonymousResourceCollection
    {
        $v = $request->validate([
            'event_type' => ['nullable', Rule::in(config('devices.event_types'))], 'severity' => ['nullable', 'in:info,warning,high,critical'],
            'acknowledged' => ['nullable', 'boolean'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $events = $device->events()->when($v['event_type'] ?? null, fn ($q, $x) => $q->where('event_type', $x))->when($v['severity'] ?? null, fn ($q, $x) => $q->where('severity', $x))
            ->when(array_key_exists('acknowledged', $v), fn ($q) => $request->boolean('acknowledged') ? $q->whereNotNull('acknowledged_at') : $q->whereNull('acknowledged_at'))
            ->when($v['from'] ?? null, fn ($q, $x) => $q->where('event_time', '>=', $x))->when($v['to'] ?? null, fn ($q, $x) => $q->where('event_time', '<=', $x))
            ->latest('event_time')->paginate($v['per_page'] ?? 20)->withQueryString();

        return DeviceEventResource::collection($events);
    }

    public function acknowledge(Request $request, DeviceEvent $event, AuditService $audit): DeviceEventResource
    {
        if (! $event->acknowledged_at) {
            $event->update(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()->getKey()]);
            $audit->record('device.event_acknowledged', $event, null, ['acknowledged' => true]);
        }

        return (new DeviceEventResource($event))->additional(['message' => 'Device event acknowledged.']);
    }

    public function all(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate([
            'event_type' => ['nullable', Rule::in(config('devices.event_types'))],
            'severity' => ['nullable', 'in:info,warning,high,critical'],
            'acknowledged' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DeviceEvent::query()
            ->with(['device.customer', 'device.emiAccount'])
            ->when($v['event_type'] ?? null, fn ($q, $x) => $q->where('event_type', $x))
            ->when($v['severity'] ?? null, fn ($q, $x) => $q->where('severity', $x))
            ->when(array_key_exists('acknowledged', $v), fn ($q) => $request->boolean('acknowledged') ? $q->whereNotNull('acknowledged_at') : $q->whereNull('acknowledged_at'))
            ->when($v['search'] ?? null, function ($q, string $s): void {
                $q->where(function ($sub) use ($s): void {
                    $sub->where('event_type', 'like', "%{$s}%")
                        ->orWhere('severity', 'like', "%{$s}%")
                        ->orWhereHas('device', fn ($dq) => $dq->where('device_code', 'like', "%{$s}%")->orWhere('brand', 'like', "%{$s}%"));
                });
            })
            ->latest('event_time');

        return DeviceEventResource::collection($query->paginate($v['per_page'] ?? 25)->withQueryString());
    }
}

