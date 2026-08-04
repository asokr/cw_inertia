<?php

namespace App\Services\Subscriber\Wb\AbTesting;

use App\Models\Subscribers\Wb\AbTesting\AbExperiment;
use App\Models\Subscribers\Wb\AbTesting\AbExperimentEvent;

class WbAbExperimentJournal
{
    public const TYPE_EXPERIMENT_STARTED = 'experiment.started';

    public const TYPE_EXPERIMENT_STOPPED = 'experiment.stopped';

    public const TYPE_EXPERIMENT_COMPLETED = 'experiment.completed';

    public const TYPE_EXPERIMENT_ERROR = 'experiment.error';

    public const TYPE_CAMPAIGN_STARTED = 'campaign.started';

    public const TYPE_CAMPAIGN_ALREADY_ACTIVE = 'campaign.already_active';

    public const TYPE_CAMPAIGN_PAUSED = 'campaign.paused';

    public const TYPE_PHOTO_SET = 'photo.set';

    public const TYPE_PHOTO_SWITCHED = 'photo.switched';

    public const TYPE_CYCLE_OPENED = 'cycle.opened';

    public const TYPE_CYCLE_CLOSED = 'cycle.closed';

    public const TYPE_API_RETRY = 'api.retry';

    public const TYPE_API_RATE_LIMITED = 'api.rate_limited';

    public const TYPE_WINNER_SELECTED = 'winner.selected';

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function log(
        AbExperiment $experiment,
        string $type,
        string $message,
        ?array $meta = null,
    ): AbExperimentEvent {
        return AbExperimentEvent::query()->create([
            'ab_experiment_id' => $experiment->id,
            'cabinet_id' => $experiment->cabinet_id,
            'type' => $type,
            'message' => mb_substr($message, 0, 500),
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }
}
