<?php

namespace App\Exports\Ozon\PriceCalc;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class FboFbsExport implements FromCollection, WithEvents
{
    private const HEADER_ROWS_COUNT = 4;

    public function __construct(
        private readonly Collection $rows,
        private readonly array $columns
    ) {}

    public function collection(): Collection
    {
        return $this->rows->map(function ($item) {
            return collect($this->columns)
                ->map(function ($column) use ($item) {
                    $key = (string) ($column['key'] ?? '');
                    $value = $item[$key] ?? $item->{$key} ?? null;

                    if ($key === 'barcode') {
                        return $this->formatBarcodeValue($value);
                    }

                    if ($this->isTextColumn($key)) {
                        return $value ?? '';
                    }

                    return $value;
                })
                ->toArray();
        });
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->insertNewRowBefore(1, self::HEADER_ROWS_COUNT);

                foreach ($this->columns as $index => $column) {
                    $columnIndex = $index + 1;
                    $sheet->setCellValueByColumnAndRow($columnIndex, 2, (string) ($column['title'] ?? ''));
                    $sheet->setCellValueByColumnAndRow($columnIndex, 3, (string) ($column['unit'] ?? ''));

                    $modeLabel = (string) ($column['mode'] ?? '');
                    $columnKey = (string) ($column['key'] ?? '');
                    if (in_array($columnKey, ['ozon_article', 'barcode'], true)) {
                        $modeLabel = '';
                    }

                    $sheet->setCellValueByColumnAndRow($columnIndex, 4, $modeLabel);
                }

                $sheet->getStyle('2:2')->getFont()->setBold(true);
                $sheet->getStyle('3:3')->getFont()->setBold(true);
                $sheet->getStyle('4:4')->getFont()->setBold(false);
                $sheet->freezePane('A5');

                $lastRow = $this->rows->count() + self::HEADER_ROWS_COUNT;
                $lastColumnIndex = count($this->columns);
                $lastColumnLetter = Coordinate::stringFromColumnIndex($lastColumnIndex);
                $fullRange = sprintf('A1:%s%d', $lastColumnLetter, $lastRow);

                foreach ($this->columns as $index => $column) {
                    $letter = Coordinate::stringFromColumnIndex($index + 1);
                    $color = $column['color'] ?? '#FFFFFF';

                    // Автоподбор ширины, чтобы не обрезало текст
                    $sheet->getColumnDimension($letter)->setAutoSize(true);

                    // Заливка и цвет шрифта для всей колонки
                    $range = sprintf('%s2:%s%d', $letter, $letter, $lastRow);

                    $sheet->getStyle($range)
                        ->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()
                        ->setARGB($this->hexToArgb($color));

                    if (! empty($column['font_color'])) {
                        $sheet->getStyle(sprintf('%s2:%s4', $letter, $letter))
                            ->getFont()
                            ->getColor()
                            ->setARGB($this->hexToArgb((string) $column['font_color']));
                    }

                    if (($column['key'] ?? '') === 'barcode') {
                        $sheet->getStyle(sprintf('%s1:%s%d', $letter, $letter, $lastRow))
                            ->getNumberFormat()
                            ->setFormatCode(NumberFormat::FORMAT_TEXT);

                        $dataRow = self::HEADER_ROWS_COUNT + 1;
                        foreach ($this->rows as $row) {
                            $rawBarcode = $row['barcode'] ?? $row->barcode ?? null;
                            $preparedBarcode = $this->formatBarcodeValue($rawBarcode);
                            if ($preparedBarcode !== '') {
                                $sheet->setCellValueExplicit(
                                    sprintf('%s%d', $letter, $dataRow),
                                    $preparedBarcode,
                                    DataType::TYPE_STRING
                                );
                            }

                            $dataRow++;
                        }
                    }
                }

                $sheet->getStyle($fullRange)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
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
        $length = strlen($normalized);

        if ($length === 3) {
            $normalized = implode('', array_map(fn($char) => $char . $char, str_split($normalized)));
        }

        if (strlen($normalized) === 6) {
            $normalized = 'FF' . $normalized;
        }

        return Str::upper($normalized);
    }

    private function isTextColumn(string $key): bool
    {
        return in_array($key, ['ozon_article', 'barcode'], true);
    }

    private function formatBarcodeValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return '';
        }

        if (is_numeric($value)) {
            // Нормализуем число в строку цифр.
            $stringValue = sprintf('%.0f', (float) $value);
        }

        return $stringValue;
    }
}
