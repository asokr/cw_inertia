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
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class PriceCalcV3Export implements FromCollection, WithHeadings, WithStyles, WithEvents
{
    private const TEXT_KEYS = [
        'brand',
        'subject_name',
        'vendor_code',
        'size',
        'barcode',
    ];

    public function __construct(
        private readonly Collection $rows,
        private readonly array $columns
    ) {}

    public function collection(): Collection
    {
        return $this->rows->map(function ($item) {
            return collect($this->columns)
                ->map(function ($column) use ($item) {
                    $key = $column['key'];
                    $value = $item[$key] ?? $item->{$key} ?? null;

                    if ($this->shouldReplaceEmptyWithZero($key, $value)) {
                        return 0;
                    }

                    return $value;
                })
                ->toArray();
        });
    }

    private function shouldReplaceEmptyWithZero(string $key, mixed $value): bool
    {
        if (in_array($key, self::TEXT_KEYS, true)) {
            return false;
        }

        return $value === null || $value === '';
    }

    public function headings(): array
    {
        return array_map(fn($column) => $column['title'], $this->columns);
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('1:1')->getFont()->setBold(true)->setSize(9);
        $sheet->getStyle('1:1')->getAlignment()
            ->setWrapText(true)
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

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
                $sheet->getRowDimension(1)->setRowHeight(92);
                $sheet->getStyle('1:1')->getFont()->setBold(true)->setSize(9);
                $sheet->getStyle('1:1')->getAlignment()
                    ->setWrapText(true)
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                foreach ($this->columns as $index => $column) {
                    $letter = Coordinate::stringFromColumnIndex($index + 1);
                    $color = $column['color'] ?? '#FFFFFF';
                    $title = str_replace(["\n", "\r"], '', (string) ($column['title'] ?? ''));
                    $isLongTitle = mb_strlen($title) > 20;
                    $width = $isLongTitle ? 15 : 11;

                    $sheet->getColumnDimension($letter)->setAutoSize(false);
                    $sheet->getColumnDimension($letter)->setWidth($width);

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
