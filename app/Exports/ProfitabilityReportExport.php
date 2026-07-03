<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class ProfitabilityReportExport implements FromView
{
    protected $reportData;
    protected $groupedItems;

    public function __construct(array $reportData, $groupedItems)
    {
        $this->reportData = $reportData;
        $this->groupedItems = $groupedItems;
    }

    /**
     * Возвращает представление для генерации Excel
     */
    public function view(): View
    {
        return view('reports.profitability', [
            'reportData' => $this->reportData,
            'items' => $this->groupedItems,
        ]);
    }
}
