<?php

namespace App\Exports\Wb;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

class ProfitabilitySummarySheet implements FromArray, WithTitle
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        private readonly array $report,
        private readonly ?string $truncatedNote = null,
    ) {
    }

    public function title(): string
    {
        return 'Итоги';
    }

    public function array(): array
    {
        $surcharges = (float) ($this->report['penalties'] ?? 0)
            + (float) ($this->report['storage_fee'] ?? 0)
            + (float) ($this->report['deduction'] ?? 0)
            + (float) ($this->report['cashback'] ?? 0)
            + (float) ($this->report['dop_rashod'] ?? 0)
            + (float) ($this->report['nalog'] ?? 0);

        $rows = [
            [
                'Показатель',
                'Значение',
            ],
            ['Период с', $this->report['date_from'] ?? ''],
            ['Период по', $this->report['date_to'] ?? ''],
            ['Продажи сумма', $this->report['sales_amount'] ?? 0],
            ['Продажи кол-во', $this->report['sales_quantity'] ?? 0],
            ['Возвраты сумма', $this->report['returns_amount'] ?? 0],
            ['Возвраты кол-во', $this->report['returns_quantity'] ?? 0],
            ['% выкупа', $this->report['percent_buy'] ?? 0],
            ['Штрафы и доплаты', $surcharges],
            ['Логистика', $this->report['logistics'] ?? 0],
            ['Себестоимость', $this->report['purchase_cost'] ?? 0],
            ['Итог', $this->report['itog'] ?? 0],
            ['Маржа', $this->report['margin'] ?? 0],
            ['Рентабельность %', $this->report['total_profitability'] ?? 0],
        ];

        if ($this->truncatedNote) {
            $rows[] = [];
            $rows[] = ['Важно', $this->truncatedNote];
        }

        return $rows;
    }
}
