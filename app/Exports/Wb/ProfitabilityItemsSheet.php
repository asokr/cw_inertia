<?php

namespace App\Exports\Wb;

use App\Models\Subscribers\Wb\Profitability\Item;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class ProfitabilityItemsSheet implements FromQuery, WithTitle, WithHeadings, WithMapping, WithCustomChunkSize
{
    /**
     * @param  list<string>  $operations
     */
    public function __construct(
        private readonly int $reportId,
        private readonly string $sheetTitle,
        private readonly array $operations,
        private readonly bool $includeType = false,
        private readonly ?int $rowLimit = null,
    ) {
    }

    public function title(): string
    {
        return mb_substr($this->sheetTitle, 0, 31);
    }

    public function query(): Builder
    {
        $query = Item::query()
            ->where('report_id', $this->reportId)
            ->whereIn('supplier_oper_name', $this->operations)
            ->orderBy('id');

        if ($this->rowLimit !== null && $this->rowLimit > 0) {
            $query->limit($this->rowLimit);
        }

        return $query;
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function headings(): array
    {
        $headings = [
            'Товар',
            'Артикул WB',
            'Размер',
            'Штрихкод',
            'Склад',
            'Кол-во',
            'Сумма к перечислению',
            'Закупочная цена',
            'Логистика',
            'Итог',
            'Затраты/доплаты',
            'Кешбэк',
            'Доп.расход',
            'Налог',
            'Маржа',
            'Рентабельность %',
            'Обоснование',
        ];

        if ($this->includeType) {
            array_unshift($headings, 'Тип');
        }

        return $headings;
    }

    /**
     * @param  Item  $item
     */
    public function map($item): array
    {
        $sum = (float) ($item->sum_to_transfer ?? 0);
        $logistics = (float) ($item->logistics ?? 0);
        $adjustments = (float) ($item->cost_adjustments ?? 0);

        $row = [
            $item->sa_name,
            $item->nm_id,
            $item->size,
            $item->barcode,
            $item->warehouse,
            (int) ($item->quantity ?? 0),
            $sum,
            (float) ($item->purchase_cost ?? 0),
            $logistics,
            $sum + $logistics + $adjustments,
            $adjustments,
            (float) ($item->cashback ?? 0),
            (float) ($item->dop_rashod ?? 0),
            (float) ($item->nalog ?? 0),
            (float) ($item->margin ?? 0),
            (float) ($item->profitability_percent ?? 0),
            $item->reasoning,
        ];

        if ($this->includeType) {
            array_unshift($row, (string) ($item->supplier_oper_name ?? ''));
        }

        return $row;
    }
}
