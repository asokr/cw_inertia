<?php

namespace App\Support\Wb;

use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves feedbacks cabinets for cron/jobs from both:
 * - unified wb_cabinets (+ wb_feedbacks_settings)
 * - legacy subs_wb_feedbacks_clients not yet migrated
 */
class FeedbacksRuntimeCabinetResolver
{
    /**
     * AI auto-answers: ai_status + ratings.
     *
     * @return Collection<int, FeedbacksRuntimeClient>
     */
    public function forAi(User $user): Collection
    {
        return $this->merge(
            $this->unified($user, ai: true, bot: false),
            $this->legacy($user, ai: true, bot: false),
        );
    }

    /**
     * Template bot: bot_status.
     *
     * @return Collection<int, FeedbacksRuntimeClient>
     */
    public function forBot(User $user): Collection
    {
        return $this->merge(
            $this->unified($user, ai: false, bot: true),
            $this->legacy($user, ai: false, bot: true),
        );
    }

    /**
     * Any active feedbacks automation (stats commands) for one user.
     *
     * @return Collection<int, FeedbacksRuntimeClient>
     */
    public function forActiveAutomation(User $user): Collection
    {
        return $this->merge(
            $this->unified($user, ai: true, bot: true, either: true),
            $this->legacy($user, ai: true, bot: true, either: true),
        );
    }

    /**
     * All active automation cabinets across users (stats batch jobs).
     *
     * @return Collection<int, FeedbacksRuntimeClient>
     */
    public function allActiveAutomation(): Collection
    {
        $unified = collect();
        if (Schema::hasTable('wb_cabinets') && Schema::hasTable('wb_feedbacks_settings')) {
            $unified = WbCabinet::query()
                ->whereHas('feedbacksSettings', function ($q) {
                    $q->where('ai_status', true)->orWhere('bot_status', true);
                })
                ->with('feedbacksSettings')
                ->get()
                ->map(fn (WbCabinet $cabinet) => FeedbacksRuntimeClient::fromWbCabinet($cabinet));
        }

        $legacy = collect();
        if (Schema::hasTable('subs_wb_feedbacks_clients')) {
            $query = FeedbacksClients::query()
                ->where(function ($q) {
                    $q->where('ai_status', true)->orWhere('bot_status', true);
                });

            if (Schema::hasColumn('subs_wb_feedbacks_clients', 'is_migrated')) {
                $query->where(function ($q) {
                    $q->where('is_migrated', false)->orWhereNull('is_migrated');
                });
            }

            $legacy = $query->get()
                ->map(fn (FeedbacksClients $client) => FeedbacksRuntimeClient::fromLegacy($client));
        }

        return $this->merge($unified, $legacy);
    }

    /**
     * @param  Collection<int, FeedbacksRuntimeClient>  $a
     * @param  Collection<int, FeedbacksRuntimeClient>  $b
     * @return Collection<int, FeedbacksRuntimeClient>
     */
    private function merge(Collection $a, Collection $b): Collection
    {
        // No id-space merge: unified and legacy ids live in different tables.
        // Process both lists fully (same numeric id may appear in both sources for different entities).
        return $a->concat($b)->values();
    }

    /**
     * @return Collection<int, FeedbacksRuntimeClient>
     */
    private function unified(User $user, bool $ai, bool $bot, bool $either = false): Collection
    {
        if (! Schema::hasTable('wb_cabinets') || ! Schema::hasTable('wb_feedbacks_settings')) {
            return collect();
        }

        $query = WbCabinet::query()
            ->where('user_id', $user->id)
            ->whereHas('feedbacksSettings', function ($q) use ($ai, $bot, $either) {
                if ($either) {
                    $q->where(function ($inner) use ($ai, $bot) {
                        if ($ai) {
                            $inner->orWhere('ai_status', true);
                        }
                        if ($bot) {
                            $inner->orWhere('bot_status', true);
                        }
                    });

                    return;
                }

                if ($ai) {
                    $q->where('ai_status', true)->whereNotNull('ai_ratings');
                }
                if ($bot) {
                    $q->where('bot_status', true);
                }
            })
            ->with('feedbacksSettings');

        return $query->get()->map(fn (WbCabinet $cabinet) => FeedbacksRuntimeClient::fromWbCabinet($cabinet));
    }

    /**
     * @return Collection<int, FeedbacksRuntimeClient>
     */
    private function legacy(User $user, bool $ai, bool $bot, bool $either = false): Collection
    {
        if (! Schema::hasTable('subs_wb_feedbacks_clients')) {
            return collect();
        }

        $subscriberId = $user->subscriberId();
        if (! $subscriberId) {
            return collect();
        }

        $query = FeedbacksClients::query()->where('subscriber_id', $subscriberId);

        if (Schema::hasColumn('subs_wb_feedbacks_clients', 'is_migrated')) {
            $query->where(function ($q) {
                $q->where('is_migrated', false)->orWhereNull('is_migrated');
            });
        }

        if ($either) {
            $query->where(function ($q) use ($ai, $bot) {
                if ($ai) {
                    $q->orWhere('ai_status', true);
                }
                if ($bot) {
                    $q->orWhere('bot_status', true);
                }
            });
        } else {
            if ($ai) {
                $query->where('ai_status', true)->whereNotNull('ai_ratings');
            }
            if ($bot) {
                $query->where('bot_status', true);
            }
        }

        return $query->get()->map(fn (FeedbacksClients $client) => FeedbacksRuntimeClient::fromLegacy($client));
    }
}
