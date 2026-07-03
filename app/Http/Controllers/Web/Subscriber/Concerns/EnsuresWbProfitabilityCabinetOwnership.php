<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;

trait EnsuresWbProfitabilityCabinetOwnership
{
    protected function ensureCabinetOwnership(ProfitabilityCabinet $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }
}