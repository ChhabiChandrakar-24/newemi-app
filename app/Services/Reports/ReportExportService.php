<?php

namespace App\Services\Reports;

use App\Exports\ArrayReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ReportExportService
{
    public function __construct(private ReportQueryService $reports) {}

    public function make(string $type, array $filters, string $format, string $path): void
    {
        $report = $this->reports->report($type, $filters, true);
        $rows = collect($report['rows'])->map(fn ($x) => method_exists($x, 'toArray') ? $x->toArray() : ((array) $x))->all();
        if ($format === 'xlsx') {
            Excel::store(new ArrayReportExport($rows), $path, 'local', \Maatwebsite\Excel\Excel::XLSX);

            return;
        }Storage::disk('local')->put($path, Pdf::loadView('reports.generic', ['title' => str($type)->replace('-', ' ')->title(), 'summary' => $report['summary'], 'rows' => $rows, 'filters' => $filters, 'generatedAt' => now()])->setPaper('a4', 'landscape')->output());
    }
}
