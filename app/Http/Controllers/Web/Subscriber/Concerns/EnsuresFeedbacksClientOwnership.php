<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Http\RedirectResponse;

trait EnsuresFeedbacksClientOwnership
{
    protected function ensureClientOwnership(WbCabinet $client): void
    {
        if ((int) $client->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }

    protected function redirectIfForeignClient(WbCabinet $client, string $to): ?RedirectResponse
    {
        if ((int) $client->user_id !== (int) auth()->id()) {
            return redirect()->to($to)->with('error', 'Кабинет не найден');
        }

        return null;
    }
}
