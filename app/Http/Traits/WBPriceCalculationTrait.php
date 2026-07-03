<?php

namespace App\Http\Traits;

use App\Services\Wb\WbPriceCalculationService;

trait WBPriceCalculationTrait
{
    protected function wbPriceCalculationService(): WbPriceCalculationService
    {
        return app(WbPriceCalculationService::class);
    }

    public function getAllCards($apiKey, $params, $cards = [])
    {
        return $this->wbPriceCalculationService()->getAllCards($apiKey, $params, $cards);
    }

    public function getSales($apiKey, $dateFrom = null)
    {
        return $this->wbPriceCalculationService()->getSales($apiKey, $dateFrom);
    }

    public function getWhTariffs($apiKey)
    {
        return $this->wbPriceCalculationService()->getWhTariffs($apiKey);
    }

    public function getWBTariffs($apiKey)
    {
        return $this->wbPriceCalculationService()->getWBTariffs($apiKey);
    }

    public function parseApiResponse($resp, $function = '')
    {
        return $this->wbPriceCalculationService()->parseApiResponse($resp, $function);
    }
}
