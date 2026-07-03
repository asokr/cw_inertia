<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;
use App\Models\Subscribers\Wb\Repricer\RepricerStocks;

trait EnsuresRepricerCabinetOwnership
{
    protected function ensureCabinetOwnership(RepricerCabinets $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }

    protected function ensureSettingBelongsToCabinet(RepricerSettings $setting, RepricerCabinets $cabinet): void
    {
        $this->ensureCabinetOwnership($cabinet);

        if ((int) $setting->cabinet_id !== (int) $cabinet->id || ! $setting->belong()) {
            abort(403);
        }
    }

    protected function ensureStockBelongsToCabinet(RepricerStocks $stock, RepricerCabinets $cabinet): void
    {
        $this->ensureCabinetOwnership($cabinet);

        if ((int) $stock->cabinet_id !== (int) $cabinet->id || ! $stock->belong()) {
            abort(403);
        }
    }
}