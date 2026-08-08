<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Oz\OzCabinet;

trait EnsuresOzCabinetOwnership
{
    protected function ensureOzCabinetOwnership(OzCabinet $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) request()->user()?->id) {
            abort(404);
        }
    }
}
