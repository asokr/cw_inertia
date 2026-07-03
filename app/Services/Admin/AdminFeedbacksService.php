<?php

namespace App\Services\Admin;

use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\Feedbacks\Review;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class AdminFeedbacksService
{
    public function listCabinets(): Collection
    {
        return FeedbacksClients::query()
            ->with(['subscriber.user:id,name,email'])
            ->select([
                'id',
                'subscriber_id',
                'name',
                'brands',
                'bot_status',
                'ai_status',
                'ai_ratings',
            ])
            ->orderBy('subscriber_id')
            ->get();
    }

    public function aiAnswerLogs(int $perPage = 25): LengthAwarePaginator
    {
        return Review::query()
            ->with(['botResponse', 'cabinet:id,name,subscriber_id'])
            ->whereHas('botResponse')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}