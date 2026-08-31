<?php

namespace App\Jobs\Wb\AbTesting;

use App\Services\Subscriber\Wb\AbTesting\WbAbExperimentEngine;
use App\Support\Wb\WbAdvertFullstatsGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Один тик на кабинет: fullstats всех running-кампаний одним запросом.
 */
class ProcessAbCabinetTickJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
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
        $this->onQueue('wb_ab_testing');
    }

    public function uniqueId(): string
    {
        return 'wb-ab-cabinet-'.$this->cabinetId;
    }

    public static function dispatchFor(int $cabinetId, int $delaySeconds = 0): void
    {
        $pending = static::dispatch($cabinetId);
        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }
    }

    public function handle(WbAbExperimentEngine $engine): void
    {
        $result = $engine->processCabinet($this->cabinetId);
        if (! ($result['reschedule'] ?? false)) {
            return;
        }

        $delay = self::RESCHEDULE_SECONDS;
        if (! ($result['success'] ?? false)) {
            $delay = max(
                self::RESCHEDULE_SECONDS,
                WbAdvertFullstatsGuard::MIN_INTERVAL_SECONDS,
                (int) ($result['retry_after'] ?? 0),
            );
        }

        self::dispatchFor($this->cabinetId, $delay);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[ProcessAbCabinetTickJob] failed', [
            'cabinet_id' => $this->cabinetId,
            'message' => $exception->getMessage(),
        ]);

        try {
            self::dispatchFor($this->cabinetId, self::RESCHEDULE_SECONDS);
        } catch (Throwable $e) {
            Log::warning('[ProcessAbCabinetTickJob] re-dispatch after failure failed', [
                'cabinet_id' => $this->cabinetId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
