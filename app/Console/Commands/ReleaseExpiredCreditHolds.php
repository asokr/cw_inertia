<?php

namespace App\Console\Commands;

use App\Services\Credits\CreditBillingService;
use Illuminate\Console\Command;

class ReleaseExpiredCreditHolds extends Command
{
    protected $signature = 'credits:release-expired-holds';

    protected $description = 'Возвращает просроченные резервы кредитов на баланс';

    public function handle(CreditBillingService $billing): int
    {
        $released = $billing->releaseExpiredHolds();
        $this->info("Возвращено резервов: {$released}");

        return self::SUCCESS;
    }
}
