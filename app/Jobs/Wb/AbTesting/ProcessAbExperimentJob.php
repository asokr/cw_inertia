<?php

namespace App\Jobs\Wb\AbTesting;

use App\Enums\WbAbTestStatus;
use App\Models\Subscribers\Wb\AbTesting\AbExperiment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Совместимость: старые job в очереди переводят тик на кабинет.
 */
class ProcessAbExperimentJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 120;

    public int $tries = 1;

    public int $timeout = 30;

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
     * @deprecated Используйте ProcessAbCabinetTickJob::dispatchFor()
     */
    public static function dispatchFor(int $experimentId, int $delaySeconds = 0): void
    {
        $experiment = AbExperiment::query()->find($experimentId);
        if (! $experiment) {
            return;
        }

        ProcessAbCabinetTickJob::dispatchFor((int) $experiment->cabinet_id, $delaySeconds);
    }

    public function handle(): void
    {
        $experiment = AbExperiment::query()->find($this->experimentId);
        if (! $experiment) {
            return;
        }

        $status = $experiment->status instanceof WbAbTestStatus
            ? $experiment->status
            : WbAbTestStatus::tryFrom((string) $experiment->status);

        if ($status !== WbAbTestStatus::Running) {
            return;
        }

        ProcessAbCabinetTickJob::dispatchFor((int) $experiment->cabinet_id);
    }
}
