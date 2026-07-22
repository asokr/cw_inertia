<?php

namespace App\Exports\Wb;

use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Events\BeforeSheet;

class ProfitabilityExport implements WithMultipleSheets, WithEvents
{
    /**
     * @param  array<string, mixed>  $report
     * @param  array{sales: int|null, returns: int|null, logistics: int|null, other: int|null}  $sheetLimits
     * @param  callable(string $sheetTitle): void|null  $onBeforeSheet
     */
    public function __construct(
        private readonly array $report,
        private readonly int $reportId,
        private readonly array $sheetLimits = [],
        private readonly ?string $truncatedNote = null,
        private $onBeforeSheet = null,
    ) {
    }

    public function sheets(): array
    {
        return [
            new ProfitabilitySummarySheet($this->report, $this->truncatedNote),
            new ProfitabilityItemsSheet(
                $this->reportId,
                'Продажи',
                ['Продажа'],
                false,
                $this->sheetLimits['sales'] ?? null,
            ),
            new ProfitabilityItemsSheet(
                $this->reportId,
                'Возвраты',
                ['Возврат'],
                false,
                $this->sheetLimits['returns'] ?? null,
            ),
            new ProfitabilityItemsSheet(
                $this->reportId,
                'Логистика',
                ['Логистика'],
                false,
                $this->sheetLimits['logistics'] ?? null,
            ),
            new ProfitabilityItemsSheet(
                $this->reportId,
                'Прочее',
                ['Штраф', 'Платная приемка', 'Удержание', 'Коррекция логистики', 'Хранение'],
                true,
                $this->sheetLimits['other'] ?? null,
            ),
        ];
    }

    public function registerEvents(): array
    {
        return [
            BeforeSheet::class => function (BeforeSheet $event) {
                if (! is_callable($this->onBeforeSheet)) {
                    return;
                }

                $title = $event->sheet->getDelegate()->getTitle();
                ($this->onBeforeSheet)((string) $title);
            },
        ];
    }
}
