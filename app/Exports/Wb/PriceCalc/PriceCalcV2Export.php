<?php

namespace App\Exports\Wb\PriceCalc;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class PriceCalcV2Export implements FromCollection, WithHeadings, WithStyles, WithEvents
{
    public function __construct(
        private readonly Collection $rows,
        private readonly array $columns
    ) {}

    public function collection(): Collection
    {
        return $this->rows->map(function ($item) {
            return collect($this->columns)
                ->map(fn($column) => $item[$column['key']] ?? $item->{$column['key']} ?? null)
                ->toArray();
        });
    }

    public function headings(): array
    {
        return array_map(fn($column) => $column['title'], $this->columns);
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('1:1')->getFont()->setBold(true);

        foreach ($this->columns as $index => $column) {
            $columnIndex = $index + 1;
            $color = $column['color'] ?? '#FFFFFF';

            $sheet->getStyleByColumnAndRow($columnIndex, 1)
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setARGB($this->hexToArgb($color));
        }

        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastRow = $this->rows->count() + 1;
                $lastColumnIndex = count($this->columns);
                $lastColumnLetter = Coordinate::stringFromColumnIndex($lastColumnIndex);
                $fullRange = sprintf('A1:%s%d', $lastColumnLetter, $lastRow);

                foreach ($this->columns as $index => $column) {
                    $letter = Coordinate::stringFromColumnIndex($index + 1);
                    $color = $column['color'] ?? '#FFFFFF';

                    $sheet->getColumnDimension($letter)->setAutoSize(true);

                    $range = sprintf('%s1:%s%d', $letter, $letter, $lastRow);

                    $sheet->getStyle($range)
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB($this->hexToArgb($color));
                }

                $sheet->getStyle($fullRange)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['argb' => 'FF000000'],
                        ],
                    ],
                ]);
            },
        ];
    }

    private function hexToArgb(string $hex): string
    {
        $normalized = ltrim($hex, '#');

        if (strlen($normalized) === 3) {
            $normalized = implode('', array_map(fn($char) => $char . $char, str_split($normalized)));
        }

        if (strlen($normalized) === 6) {
            $normalized = 'FF' . $normalized;
        }

        return Str::upper($normalized);
    }
}
