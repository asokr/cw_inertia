<?php

namespace App\Models\Subscribers\Wb\AbTesting;

use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbExperimentCycle extends Model
{
    public const END_IMPRESSIONS = 'impressions_limit';

    public const END_TIME = 'time_limit';

    public const END_COMPLETED = 'experiment_completed';

    public const END_STOPPED = 'experiment_stopped';

    public const END_ERROR = 'error';

    /** Пользователь удалил вариант фото во время running. */
    public const END_PHOTO_REMOVED = 'photo_removed';

    protected $table = 'wb_ab_experiment_cycles';

    protected $fillable = [
        'ab_experiment_id',
        'cabinet_id',
        'ab_experiment_photo_id',
        'sequence',
        'started_at',
        'ended_at',
        'end_reason',
        'views_start',
        'views_end',
        'clicks_start',
        'clicks_end',
        'spend_start',
        'spend_end',
        'orders_start',
        'orders_end',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'views_start' => 'integer',
        'views_end' => 'integer',
        'clicks_start' => 'integer',
        'clicks_end' => 'integer',
        'spend_start' => 'float',
        'spend_end' => 'float',
        'orders_start' => 'integer',
        'orders_end' => 'integer',
    ];

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(AbExperiment::class, 'ab_experiment_id');
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(AbExperimentPhoto::class, 'ab_experiment_photo_id');
    }

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    public function deltaViews(?int $viewsEnd = null): int
    {
        $end = $viewsEnd ?? $this->views_end;
        if ($end === null) {
            return 0;
        }

        return max(0, (int) $end - (int) $this->views_start);
    }

    public function deltaClicks(?int $clicksEnd = null): int
    {
        $end = $clicksEnd ?? $this->clicks_end;
        if ($end === null) {
            return 0;
        }

        return max(0, (int) $end - (int) $this->clicks_start);
    }
}
