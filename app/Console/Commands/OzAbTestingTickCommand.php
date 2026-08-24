<?php

namespace App\Console\Commands;

use App\Enums\OzAbTestStatus;
use App\Jobs\Oz\AbTesting\ProcessOzAbCabinetTickJob;
use App\Models\Subscribers\Oz\AbTesting\AbExperiment;
use Illuminate\Console\Command;

/**
 * Fallback: если цепочка job оборвалась, снова ставит тик кабинета в очередь.
 */
class OzAbTestingTickCommand extends Command
{
    protected $signature = 'subscriber:oz-ab-testing-tick';

    protected $description = 'Fallback: поставить в очередь тик A/B по кабинетам Ozon с running-экспериментами';

    public function handle(): int
    {
        $cabinetIds = AbExperiment::query()
            ->where('status', OzAbTestStatus::Running->value)
            ->distinct()
            ->orderBy('cabinet_id')
            ->pluck('cabinet_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        foreach ($cabinetIds as $cabinetId) {
            ProcessOzAbCabinetTickJob::dispatchFor($cabinetId);
        }

        if ($cabinetIds !== []) {
            $this->info('Fallback dispatched '.count($cabinetIds).' Ozon A/B cabinet tick job(s).');
        }

        return self::SUCCESS;
    }
}
