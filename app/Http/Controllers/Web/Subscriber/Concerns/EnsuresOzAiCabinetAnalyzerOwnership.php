<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerAiAnalysis;
use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerReport;
use App\Models\Subscribers\Oz\OzCabinet;

trait EnsuresOzAiCabinetAnalyzerOwnership
{
    protected function ensureCabinetOwnership(OzCabinet $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }

    protected function ensureReportOwnership(OzAiCabinetAnalyzerReport $report): void
    {
        $report->loadMissing('cabinet');

        if (! $report->cabinet || (int) $report->cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }

    protected function ensureAnalysisOwnership(OzAiCabinetAnalyzerAiAnalysis $analysis): void
    {
        $analysis->loadMissing('report.cabinet');

        if (
            ! $analysis->report
            || ! $analysis->report->cabinet
            || (int) $analysis->report->cabinet->user_id !== (int) auth()->id()
        ) {
            abort(403);
        }
    }
}
