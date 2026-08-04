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
        // Group by cabinet so multiple experiments on one token don't burst fullstats.
        $rows = AbExperiment::query()
            ->where('status', WbAbTestStatus::Running->value)
            ->orderBy('cabinet_id')
            ->orderBy('id')
            ->get(['id', 'cabinet_id']);

        $count = 0;
        $offsetByCabinet = [];
        foreach ($rows as $row) {
            $cabinetId = (int) $row->cabinet_id;
            $offset = (int) ($offsetByCabinet[$cabinetId] ?? 0);
            $offsetByCabinet[$cabinetId] = $offset + 20; // fullstats interval ~20s

            // UniqueUntilProcessing drops duplicates if a tick job is already queued/processing.
            ProcessAbExperimentJob::dispatchFor((int) $row->id, $offset);
            $count++;
        }

        if ($count > 0) {
            $this->info("Fallback dispatched {$count} A/B experiment job(s).");
        }

        return self::SUCCESS;
    }
}
