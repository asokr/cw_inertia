<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Wb\WbCabinet;

trait EnsuresAiCabinetAnalyzerOwnership
{
    protected function ensureCabinetOwnership(WbCabinet $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }
}
