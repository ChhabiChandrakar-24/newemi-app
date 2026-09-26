<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ArrayReportExport implements FromCollection, WithHeadings, WithStyles
{
    public function __construct(private array $rows) {}

    public function collection(): Collection
    {
        return collect($this->rows)->map(fn ($r) => collect($r)->map(fn ($v) => $this->safe($v))->values()->all());
    }

    public function headings(): array
    {
        return array_keys($this->rows[0] ?? ['message' => 'No records found for selected filters.']);
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());

        return [];
    }

    private function safe(mixed $v): mixed
    {
        if (is_array($v) || is_object($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        }if (is_string($v) && preg_match('/^[=+\-@]/', $v)) {
            return "'".$v;
        }

        return $v;
    }
}
