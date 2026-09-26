<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\GeneratedReport;
use App\Services\Reports\ReportExportService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $reportId, public ?int $companyId = null) {}

    public function handle(ReportExportService $exports): void
    {
        $report = GeneratedReport::withoutGlobalScopes()->find($this->reportId);
        $company = Company::find($this->companyId ?? $report?->company_id);
        if (! $company) {
            return;
        }
        app(TenantContext::class)->set($company);
        $r = GeneratedReport::find($this->reportId);
        if (! $r || $r->status === 'completed') {
            return;
        }$r->update(['status' => 'processing', 'started_at' => now()]);
        try {
            $path = "reports/{$r->report_uuid}.{$r->format}";
            $exports->make($r->report_type, $r->filters, $r->format, $path);
            $r->update(['status' => 'completed', 'file_path' => $path, 'completed_at' => now()]);
        } catch (\Throwable $e) {
            $r->update(['status' => 'failed', 'error' => str($e->getMessage())->limit(500)]);
            throw $e;
        }
    }
}
