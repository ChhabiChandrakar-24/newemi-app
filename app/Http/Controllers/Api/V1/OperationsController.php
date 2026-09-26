<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEvent;
use App\Models\GeneratedReport;
use App\Models\ScheduledReport;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    public function publicHealth(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'service' => 'emi-locking-backend']);
    }

    public function alerts(Request $r): JsonResponse
    {
        $items = DeviceEvent::with('device:id,device_code,display_name')->when($r->severity, fn ($q, $v) => $q->where('severity', $v))->when($r->event_type, fn ($q, $v) => $q->where('event_type', $v))->when($r->has('acknowledged'), fn ($q) => $r->boolean('acknowledged') ? $q->whereNotNull('acknowledged_at') : $q->whereNull('acknowledged_at'))->latest('event_time')->paginate(min((int) $r->input('per_page', 20), 100));

        return response()->json($items);
    }

    public function audit(Request $r): JsonResponse
    {
        $query = AuditLog::query()
            ->when($r->filled('search'), function ($q) use ($r) {
                $s = $r->input('search');
                $q->where(function ($sq) use ($s) {
                    $sq->where('action', 'like', "%{$s}%")
                        ->orWhere('actor_name', 'like', "%{$s}%")
                        ->orWhere('actor_email', 'like', "%{$s}%")
                        ->orWhere('entity_type', 'like', "%{$s}%")
                        ->orWhere('remarks', 'like', "%{$s}%")
                        ->orWhere('ip_address', 'like', "%{$s}%");
                });
            })
            ->when($r->filled('action'), fn ($q) => $q->where('action', 'like', "%{$r->input('action')}%"))
            ->when($r->filled('entity_type'), fn ($q) => $q->where('entity_type', 'like', "%{$r->input('entity_type')}%"))
            ->when($r->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $r->input('date_from')))
            ->when($r->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $r->input('date_to')))
            ->latest();

        return response()->json($query->paginate(min((int) $r->input('per_page', 25), 100)));
    }

    public function commands(Request $r): JsonResponse
    {
        $v = $r->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'command_type' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DeviceCommand::query()
            ->with([
                'device:id,device_code,display_name,brand,model,customer_id,control_status,connectivity_status',
                'device.customer:id,full_name,mobile_number',
                'requester:id,name',
            ])
            ->when($v['command_type'] ?? null, fn ($q, $t) => $q->where('command_type', $t))
            ->when($v['status'] ?? null, function ($q, $s): void {
                if ($s === 'pending') {
                    $q->whereIn('status', ['queued', 'sent']);
                } elseif ($s === 'completed') {
                    $q->whereIn('status', ['acknowledged', 'completed']);
                } elseif ($s === 'failed') {
                    $q->whereIn('status', ['failed', 'cancelled']);
                } else {
                    $q->where('status', $s);
                }
            })
            ->when($v['search'] ?? null, function ($q, string $s): void {
                $q->where(function ($sub) use ($s): void {
                    $sub->where('command_uuid', 'like', "%{$s}%")
                        ->orWhere('command_type', 'like', "%{$s}%")
                        ->orWhereHas('device', function ($dq) use ($s): void {
                            $dq->where('device_code', 'like', "%{$s}%")
                                ->orWhere('brand', 'like', "%{$s}%")
                                ->orWhere('model', 'like', "%{$s}%")
                                ->orWhereHas('customer', fn ($cq) => $cq->where('full_name', 'like', "%{$s}%")->orWhere('mobile_number', 'like', "%{$s}%"));
                        })
                        ->orWhereHas('requester', fn ($rq) => $rq->where('name', 'like', "%{$s}%"));
                });
            })
            ->when($v['date_from'] ?? null, fn ($q, $d) => $q->whereDate('requested_at', '>=', $d))
            ->when($v['date_to'] ?? null, fn ($q, $d) => $q->whereDate('requested_at', '<=', $d))
            ->latest('requested_at');

        $stats = [
            'total' => DeviceCommand::count(),
            'pending' => DeviceCommand::whereIn('status', ['queued', 'sent'])->count(),
            'completed' => DeviceCommand::whereIn('status', ['acknowledged', 'completed'])->count(),
            'failed' => DeviceCommand::whereIn('status', ['failed', 'cancelled'])->count(),
        ];

        $paginator = $query->paginate($v['per_page'] ?? 20);

        return response()->json([
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'meta' => [
                'stats' => $stats,
            ],
        ]);
    }

    public function health(SettingsService $settings): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'connected';
        } catch (\Throwable) {
            $database = 'failed';
        }$cache = rescue(function (): string {
            $key = 'health:'.str()->random();
            Cache::put($key, true, 10);
            $ok = Cache::get($key) === true;
            Cache::forget($key);

            return $ok ? 'connected' : 'failed';
        }, 'failed', false);
        $backup = BackupRun::latest()->first();

        return response()->json(['data' => ['backend' => 'healthy', 'version' => config('app.version'), 'database' => $database, 'cache' => $cache, 'storage' => is_writable(storage_path('app')) ? 'writable' : 'failed', 'backup' => ['enabled' => config('backup.enabled'), 'last_status' => $backup?->status, 'last_completed_at' => $backup?->completed_at], 'queue' => ['failed_jobs' => DB::table('failed_jobs')->count()], 'reports' => ['queued' => GeneratedReport::whereIn('status', ['queued', 'processing'])->count(), 'failed' => GeneratedReport::where('status', 'failed')->count(), 'scheduled_failures' => ScheduledReport::where('is_active', true)->where('next_run_at', '<', now()->subHour())->count()], 'integrations' => collect(['firebase', 'email', 'sms', 'payment'])->mapWithKeys(fn ($p) => [$p => ['configured' => collect($settings->category($p))->isNotEmpty()]]), 'devices' => ['online' => Device::where('connectivity_status', 'online')->count(), 'offline' => Device::where('connectivity_status', 'offline')->count()], 'commands' => ['pending' => DeviceCommand::whereIn('status', DeviceCommand::ACTIVE_STATUSES)->count(), 'failed_24h' => DeviceCommand::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count()]]]);
    }

    public function acknowledgeAlert(Request $request, DeviceEvent $event): JsonResponse
    {
        abort_if($event->acknowledged_at, 422, 'Alert is already acknowledged.');
        $event->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()->id])->save();

        return response()->json(['message' => 'Alert acknowledged.', 'data' => $event]);
    }

    public function bulkAcknowledgeAlerts(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'distinct']])['ids'];
        $count = DeviceEvent::whereKey($ids)->whereNull('acknowledged_at')->update(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()->id]);

        return response()->json(['message' => "{$count} alerts acknowledged.", 'updated' => $count]);
    }
}
