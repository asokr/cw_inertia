<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;

trait EnsuresWbPriceCalcCabinetOwnership
{
    protected function ensureCabinetOwnership(PriceCalculationCabinets $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }
}