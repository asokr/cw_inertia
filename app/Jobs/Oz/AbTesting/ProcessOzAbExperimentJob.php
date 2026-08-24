<?php

namespace App\Jobs\Oz\AbTesting;

use App\Enums\OzAbTestStatus;
use App\Models\Subscribers\Oz\AbTesting\AbExperiment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Совместимость: старые job в очереди переводят тик на кабинет.
 */
class ProcessOzAbExperimentJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 180;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly int $experimentId,
    ) {
        $this->onQueue('oz_ab_testing');
    }

    public function uniqueId(): string
    {
        return 'oz-ab-experiment-'.$this->experimentId;
    }

    public function handle(): void
    {
        $experiment = AbExperiment::query()->find($this->experimentId);
        if (! $experiment) {
            return;
        }

        $status = $experiment->status instanceof OzAbTestStatus
            ? $experiment->status
            : OzAbTestStatus::tryFrom((string) $experiment->status);

        if ($status !== OzAbTestStatus::Running) {
            return;
        }

        ProcessOzAbCabinetTickJob::dispatchFor((int) $experiment->cabinet_id);
    }
}
