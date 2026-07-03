<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcCabinet;

trait EnsuresOzPriceCalcCabinetOwnership
{
    protected function ensureCabinetOwnership(OzPriceCalcCabinet $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }
}