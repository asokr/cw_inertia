<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerAiAnalysis;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerCabinet;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerReport;

trait EnsuresAiCabinetAnalyzerOwnership
{
    protected function ensureCabinetOwnership(AiCabinetAnalyzerCabinet $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }

    protected function ensureReportOwnership(AiCabinetAnalyzerReport $report): void
    {
        $report->loadMissing('cabinet');

        if (! $report->cabinet || (int) $report->cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }

    protected function ensureAnalysisOwnership(AiCabinetAnalyzerAiAnalysis $analysis): void
    {
        $analysis->loadMissing('report.cabinet');

        if (! $analysis->report || ! $analysis->report->cabinet || (int) $analysis->report->cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }
}