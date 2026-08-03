<?php

namespace App\Console\Commands;

use App\Enums\WbAbTestStatus;
use App\Jobs\Wb\AbTesting\ProcessAbExperimentJob;
use App\Models\Subscribers\Wb\AbTesting\AbExperiment;
use Illuminate\Console\Command;

/**
 * Fallback only: primary loop is ProcessAbExperimentJob self-reschedule after start.
 * Re-enqueues running experiments if the chain was lost (queue clear, worker crash, etc.).
 */
class WbAbTestingTickCommand extends Command
{
    protected $signature = 'subscriber:wb-ab-testing-tick';

    protected $description = 'Fallback: поставить в очередь обработку running A/B-экспериментов WB (если цепочка job оборвалась)';

    public function handle(): int
    {
        $ids = AbExperiment::query()
            ->where('status', WbAbTestStatus::Running->value)
            ->orderBy('id')
            ->pluck('id');

        $count = 0;
        foreach ($ids as $id) {
            // UniqueUntilProcessing drops duplicates if a tick job is already queued/processing.
            ProcessAbExperimentJob::dispatchFor((int) $id);
            $count++;
        }

        if ($count > 0) {
            $this->info("Fallback dispatched {$count} A/B experiment job(s).");
        }

        return self::SUCCESS;
    }
}
