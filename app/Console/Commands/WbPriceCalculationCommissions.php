<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\Wb\WbPriceCalculationService;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCommissions;

class WbPriceCalculationCommissions extends Command
{
    private WbPriceCalculationService $wbPriceCalculationService;

    public function __construct(WbPriceCalculationService $wbPriceCalculationService)
    {
        parent::__construct();

        $this->wbPriceCalculationService = $wbPriceCalculationService;
    }
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriber:wb-price-calculation-commissions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $apiKeys = $this->getAllApiKeys();

        if ($apiKeys->isEmpty()) {
            Log::warning('Нет доступных API ключей для загрузки комиссий WB');
            return;
        }

        $wbTariffs = null;
        $success = false;

        foreach ($apiKeys as $apiKey) {
            $wbTariffsResponse = $this->wbPriceCalculationService->getWBTariffs($apiKey);
            $wbTariffs = $this->wbPriceCalculationService->parseApiResponse($wbTariffsResponse, 'getWBTariffs');

            if ($wbTariffs['success'] && !empty(data_get($wbTariffs['data'], 'report'))) {
                $success = true;
                break; // Успешно получили данные, выходим из цикла
            }

            // Если не успешно, пробуем следующий ключ без задержки
        }

        if (!$success) {
            Log::info('Не смогли получить коммиссии WB по категориям товаров ни с одним из ключей');
            return;
        }

        foreach (data_get($wbTariffs['data'], 'report', []) as $item) {
            PriceCalculationCommissions::updateOrCreate(
                ['subjectID' => $item['subjectID']],
                ['subjectID' => $item['subjectID'], "data" => $item]
            );
        }
    }

    private function getAllApiKeys()
    {
        $keys = FeedbacksClients::query()
            ->where(function ($query) {
                $query->where('bot_status', 1)
                    ->orWhere('ai_status', 1);
            })
            ->whereNotNull('apikey')
            ->pluck('apikey');

        $fallback = env('WB_API_KEY');
        if ($fallback) {
            $keys->push($fallback);
        }

        return $keys->unique();
    }
}
