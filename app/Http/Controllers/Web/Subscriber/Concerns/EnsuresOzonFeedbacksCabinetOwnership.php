<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use App\Models\Subscribers\Oz\Feedbacks\FeedbacksClients;

trait EnsuresOzonFeedbacksCabinetOwnership
{
    protected function ensureCabinetOwnership(FeedbacksClients $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }
}