<?php

namespace App\Jobs\Oz\AbTesting;

use App\Services\Subscriber\Oz\AbTesting\OzAbExperimentEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Один тик на кабинет: статистика всех running-экспериментов одним запросом.
 */
class ProcessOzAbCabinetTickJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 180;

    public int $tries = 1;

    public int $timeout = 120;

    public const RESCHEDULE_SECONDS = 60;

    public function __construct(
        public readonly int $cabinetId,
    ) {
        $this->onQueue('oz_ab_testing');
    }

    public function uniqueId(): string
    {
        return 'oz-ab-cabinet-'.$this->cabinetId;
    }

    public static function dispatchFor(int $cabinetId, int $delaySeconds = 0): void
    {
        $pending = static::dispatch($cabinetId);
        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }
    }

    public function handle(OzAbExperimentEngine $engine): void
    {
        $result = $engine->processCabinet($this->cabinetId);
        if ($result['reschedule'] ?? false) {
            self::dispatchFor($this->cabinetId, self::RESCHEDULE_SECONDS);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[ProcessOzAbCabinetTickJob] failed', [
            'cabinet_id' => $this->cabinetId,
            'message' => $exception->getMessage(),
        ]);

        try {
            self::dispatchFor($this->cabinetId, self::RESCHEDULE_SECONDS);
        } catch (Throwable $e) {
            Log::warning('[ProcessOzAbCabinetTickJob] re-dispatch after failure failed', [
                'cabinet_id' => $this->cabinetId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
