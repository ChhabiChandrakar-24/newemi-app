<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportJob;
use App\Models\GeneratedReport;
use App\Models\ScheduledReport;
use App\Services\Reports\ReportQueryService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function show(Request $request, string $type, ReportQueryService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->report($type, $request)]);
    }

    public function generate(Request $request): JsonResponse
    {
        $v = $request->validate(['report_type' => ['required', 'in:'.implode(',', ReportQueryService::TYPES)], 'format' => ['required', 'in:xlsx,pdf'], 'filters' => ['sometimes', 'array']]);
        $r = GeneratedReport::create(['report_uuid' => (string) Str::uuid(), 'report_type' => $v['report_type'], 'requested_by' => $request->user()->id, 'format' => $v['format'], 'filters' => $v['filters'] ?? [], 'status' => 'queued', 'requested_at' => now(), 'expires_at' => now()->addDays(7)]);
        GenerateReportJob::dispatch($r->id, $r->company_id);

        return response()->json(['data' => $r], 202);
    }

    public function generated(Request $request): JsonResponse
    {
        return response()->json(GeneratedReport::with('requester:id,name')->when(! $request->user()->hasAnyRole(['super-admin', 'admin']), fn ($q) => $q->where('requested_by', $request->user()->id))->latest()->paginate(25));
    }

    public function generatedShow(Request $request, GeneratedReport $report): JsonResponse
    {
        $this->authorizeReport($request, $report);

        return response()->json(['data' => $report->load('requester:id,name')]);
    }

    public function download(Request $request, GeneratedReport $report): StreamedResponse
    {
        $this->authorizeReport($request, $report);
        abort_unless($report->status === 'completed' && $report->file_path && Storage::disk('local')->exists($report->file_path), 404);

        return Storage::disk('local')->download($report->file_path, str($report->report_type)->slug().'-'.$report->completed_at?->format('Y-m-d').'.'.$report->format, ['Content-Type' => $report->format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function schedules(Request $request): JsonResponse
    {
        return response()->json(ScheduledReport::latest()->paginate(25));
    }

    public function scheduleStore(Request $request): JsonResponse
    {
        $v = $this->scheduleRules($request);
        $r = ScheduledReport::create(array_merge(['filters' => [], 'schedule_config' => [], 'recipients' => [], 'is_active' => true], $v, ['created_by' => $request->user()->id, 'next_run_at' => $this->next($v['schedule_type'])]));

        return response()->json(['data' => $r], 201);
    }

    public function scheduleUpdate(Request $request, ScheduledReport $report): JsonResponse
    {
        $v = $this->scheduleRules($request);
        $report->update(array_merge(['filters' => [], 'schedule_config' => [], 'recipients' => []], $v, ['next_run_at' => $this->next($v['schedule_type'])]));

        return response()->json(['data' => $report]);
    }

    private function scheduleRules(Request $r): array
    {
        return $r->validate(['name' => ['required', 'string', 'max:150'], 'report_type' => ['required', 'in:'.implode(',', ReportQueryService::TYPES)], 'filters' => ['sometimes', 'array'], 'format' => ['required', 'in:xlsx,pdf'], 'schedule_type' => ['required', 'in:daily,weekly,monthly'], 'schedule_config' => ['sometimes', 'array'], 'delivery_channels' => ['required', 'array'], 'delivery_channels.*' => ['in:dashboard,email'], 'recipients' => ['sometimes', 'array'], 'recipients.*' => ['email'], 'is_active' => ['boolean']]);
    }

    private function next(string $type): CarbonInterface
    {
        return match ($type) {
            'daily' => now()->addDay()->startOfDay(),'weekly' => now()->addWeek()->startOfWeek(),'monthly' => now()->addMonth()->startOfMonth()
        };
    }

    private function authorizeReport(Request $r, GeneratedReport $report): void
    {
        abort_unless($report->requested_by === $r->user()->id || $r->user()->hasAnyRole(['super-admin', 'admin']), 403);
    }
}
