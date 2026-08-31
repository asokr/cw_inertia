<?php

namespace App\Console\Commands;

use App\Enums\WbAbTestStatus;
use App\Jobs\Wb\AbTesting\ProcessAbCabinetTickJob;
use App\Models\Subscribers\Wb\AbTesting\AbExperiment;
use Illuminate\Console\Command;

/**
 * Fallback only: primary loop is ProcessAbCabinetTickJob self-reschedule after start.
 * Re-enqueues running cabinets if the chain was lost (queue clear, worker crash, etc.).
 */
class WbAbTestingTickCommand extends Command
{
    protected $signature = 'subscriber:wb-ab-testing-tick';

    protected $description = 'Fallback: поставить в очередь тик кабинетов с running A/B-экспериментами WB (если цепочка job оборвалась)';

    public function handle(): int
    {
        $cabinetIds = AbExperiment::query()
            ->where('status', WbAbTestStatus::Running->value)
            ->distinct()
            ->orderBy('cabinet_id')
            ->pluck('cabinet_id');

        $count = 0;
        foreach ($cabinetIds as $cabinetId) {
            ProcessAbCabinetTickJob::dispatchFor((int) $cabinetId);
            $count++;
        }

        if ($count > 0) {
            $this->info("Fallback dispatched {$count} A/B cabinet tick job(s).");
        }

        return self::SUCCESS;
    }
}
