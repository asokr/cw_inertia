<?php

namespace App\Jobs\Wb\AbTesting;

use App\Enums\WbAbTestStatus;
use App\Models\Subscribers\Wb\AbTesting\AbExperiment;
use App\Services\Subscriber\Wb\AbTesting\WbAbExperimentEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Primary path: dispatched on experiment start, then self-reschedules every minute while running.
 * Fallback: subscriber:wb-ab-testing-tick every 2 minutes after queue wipe / lost chain.
 *
 * ShouldBeUniqueUntilProcessing: lock released when processing starts so we can re-dispatch
 * the next delayed job from handle() without unique conflict.
 */
class ProcessAbExperimentJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 120;

    public int $tries = 1;

    public int $timeout = 120;

    /** Seconds until the next tick for the same experiment. */
    public const RESCHEDULE_SECONDS = 60;

    public function __construct(
        public readonly int $experimentId,
    ) {
        $this->onQueue('wb_ab_testing');
    }

    public function uniqueId(): string
    {
        return 'wb-ab-experiment-'.$this->experimentId;
    }

    /**
     * Enqueue processing (optional delay). Used on start and by the tick fallback.
     */
    public static function dispatchFor(int $experimentId, int $delaySeconds = 0): void
    {
        $pending = static::dispatch($experimentId);
        if ($delaySeconds > 0) {
            $pending->delay(now()->addSeconds($delaySeconds));
        }
    }

    public function handle(WbAbExperimentEngine $engine): void
    {
        $experiment = AbExperiment::query()
            ->with(['photos', 'product', 'cabinet'])
            ->find($this->experimentId);

        if (! $experiment) {
            return;
        }

        $status = $this->resolveStatus($experiment);
        if ($status !== WbAbTestStatus::Running) {
            return;
        }

        $result = $engine->process($experiment);

        $action = (string) ($result['action'] ?? '');
        if (! ($result['success'] ?? false) && $action === 'error') {
            Log::warning('[ProcessAbExperimentJob] experiment error', [
                'experiment_id' => $this->experimentId,
                'messages' => $result['messages'] ?? [],
            ]);
        }

        // Chain next tick only while still running (not completed / stopped / error).
        $fresh = AbExperiment::query()->find($this->experimentId);
        if ($fresh && $this->resolveStatus($fresh) === WbAbTestStatus::Running) {
            $delay = self::RESCHEDULE_SECONDS;
            if ($action === 'rate_limited') {
                $delay = max(
                    self::RESCHEDULE_SECONDS,
                    (int) ($result['retry_after'] ?? self::RESCHEDULE_SECONDS),
                );
            }
            self::dispatchFor($this->experimentId, $delay);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('[ProcessAbExperimentJob] failed', [
            'experiment_id' => $this->experimentId,
            'message' => $exception->getMessage(),
        ]);

        // Try to keep the chain alive after a hard failure (fallback tick will also help).
        $fresh = AbExperiment::query()->find($this->experimentId);
        if ($fresh && $this->resolveStatus($fresh) === WbAbTestStatus::Running) {
            try {
                self::dispatchFor($this->experimentId, self::RESCHEDULE_SECONDS);
            } catch (Throwable $e) {
                Log::warning('[ProcessAbExperimentJob] re-dispatch after failure failed', [
                    'experiment_id' => $this->experimentId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolveStatus(AbExperiment $experiment): ?WbAbTestStatus
    {
        return $experiment->status instanceof WbAbTestStatus
            ? $experiment->status
            : WbAbTestStatus::tryFrom((string) $experiment->status);
    }
}
