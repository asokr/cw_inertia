<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use Illuminate\Http\RedirectResponse;

trait EnsuresFeedbacksClientOwnership
{
    protected function ensureClientOwnership(FeedbacksClients $client): void
    {
        $subscriberId = auth()->user()->subscriber?->id;

        if (! $subscriberId || (int) $client->subscriber_id !== (int) $subscriberId) {
            abort(403);
        }
    }

    protected function redirectIfForeignClient(FeedbacksClients $client, string $to): ?RedirectResponse
    {
        $subscriberId = auth()->user()->subscriber?->id;

        if (! $subscriberId || (int) $client->subscriber_id !== (int) $subscriberId) {
            return redirect()->to($to)->with('error', 'Кабинет не найден');
        }

        return null;
    }
}