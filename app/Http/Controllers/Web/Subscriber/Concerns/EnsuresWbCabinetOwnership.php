<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Wb\WbCabinet;

trait EnsuresWbCabinetOwnership
{
    protected function ensureCabinetOwnership(WbCabinet $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) auth()->id()) {
            abort(404);
        }
    }

    protected function ensureSelectedCabinet(?WbCabinet $cabinet): WbCabinet
    {
        if (! $cabinet) {
            abort(404, 'Добавьте хотя бы один кабинет Wildberries.');
        }

        $this->ensureCabinetOwnership($cabinet);

        return $cabinet;
    }
}
