<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Wb\Repricer\RepricerSettings;
use App\Models\Subscribers\Wb\Repricer\RepricerStocks;
use App\Models\Subscribers\Wb\WbCabinet;

trait EnsuresRepricerCabinetOwnership
{
    protected function ensureCabinetOwnership(WbCabinet $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }

    protected function ensureSettingBelongsToCabinet(RepricerSettings $setting, WbCabinet $cabinet): void
    {
        $this->ensureCabinetOwnership($cabinet);

        if ((int) $setting->cabinet_id !== (int) $cabinet->id) {
            abort(404);
        }
    }

    protected function ensureStockBelongsToCabinet(RepricerStocks $stock, WbCabinet $cabinet): void
    {
        $this->ensureCabinetOwnership($cabinet);

        if ((int) $stock->cabinet_id !== (int) $cabinet->id) {
            abort(404);
        }
    }
}
