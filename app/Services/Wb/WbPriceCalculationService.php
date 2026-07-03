<?php

namespace App\Services\Wb;

use App\Http\Traits\GuzzleTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class WbPriceCalculationService
{
    use GuzzleTrait;

    public function getAllCards(string $apiKey, array $params, array $cards = [])
    {
        $url = 'https://content-api.wildberries.ru/content/v2/get/cards/list?locale=ru';

        $result = $this->postRequest($url, $apiKey, $params);

        if (! $result) {
            return [
                'code' => 503,
                'response' => json_encode(['message' => 'Ошибка доступа к API'], JSON_UNESCAPED_UNICODE),
            ];
        }

        if (($result['code'] ?? null) !== 200) {
            return $result;
        }

        $data = json_decode($result['response'] ?? '', true);

        if (! is_array($data)) {
            return [
                'code' => 503,
                'response' => $result['response'] ?? '',
            ];
        }

        array_push($cards, ...($data['cards'] ?? []));

        if (($data['cursor']['total'] ?? 0) >= ($params['settings']['cursor']['limit'] ?? 0)) {
            $params['settings']['cursor']['updatedAt'] = $data['cursor']['updatedAt'] ?? null;
            $params['settings']['cursor']['nmID'] = $data['cursor']['nmID'] ?? null;

            return $this->getAllCards($apiKey, $params, $cards);
        }

        return [
            'code' => 200,
            'response' => json_encode(['cards' => $cards], JSON_UNESCAPED_UNICODE),
        ];
    }

    public function getSales(string $apiKey, ?Carbon $dateFrom = null)
    {
        $url = 'https://statistics-api.wildberries.ru/api/v1/supplier/sales';
        $dateFromStr = ($dateFrom ?? Carbon::now()->subDays(30))->toDateString();

        $allSales = [];
        $flag = 0;

        while (true) {
            $params = [
                'dateFrom' => $dateFromStr,
                'flag' => $flag,
            ];

            $result = $this->getRequest($url, $apiKey, $params);

            if (($result['code'] ?? null) !== 200) {
                if (empty($allSales)) {
                    return $result;
                }
                break;
            }

            $data = json_decode($result['response'] ?? '', true);

            if (!is_array($data) || empty($data)) {
                break;
            }

            $allSales = array_merge($allSales, $data);

            if (count($data) < 80000) {
                break;
            }

            $lastItem = end($data);
            if (!isset($lastItem['lastChangeDate'])) {
                break;
            }

            $dateFromStr = $lastItem['lastChangeDate'];
            sleep(1);
        }

        // Фильтруем данные, оставляя только те, которые попадают в нужный месяц и проданы со склада WB
        if ($dateFrom) {
            $endDate = $dateFrom->copy()->endOfMonth();
            $allSales = array_filter($allSales, function ($sale) use ($dateFrom, $endDate) {
                if (!isset($sale['date'])) {
                    return false;
                }

                // Фильтр по типу склада
                if (isset($sale['warehouseType']) && $sale['warehouseType'] !== 'Склад WB') {
                    return false;
                }

                $saleDate = Carbon::parse($sale['date']);
                return $saleDate->between($dateFrom, $endDate);
            });
            // Сбрасываем ключи массива после фильтрации
            $allSales = array_values($allSales);
        }

        return [
            'code' => 200,
            'response' => json_encode($allSales, JSON_UNESCAPED_UNICODE),
        ];
    }

    public function getWhTariffs(string $apiKey)
    {
        $url = 'https://common-api.wildberries.ru/api/v1/tariffs/box';

        $params = [
            'date' => Carbon::now()->toDateString(),
        ];

        return $this->getRequest($url, $apiKey, $params);
    }

    public function getWBTariffs(string $apiKey)
    {
        $url = 'https://common-api.wildberries.ru/api/v1/tariffs/commission';

        $params = [
            'locale' => 'ru',
        ];

        return $this->getRequest($url, $apiKey, $params);
    }

    public function getReportDetailByPeriod(string $apiKey, Carbon $dateFrom, Carbon $dateTo, int $limit = 100000, int $rrdid = 0)
    {
        $url = 'https://finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed';

        $payload = [
            'dateFrom' => $dateFrom->toDateString(),
            'dateTo' => $dateTo->toDateString(),
            'limit' => $limit,
            'rrdId' => $rrdid,
            'period' => 'daily',
            'fields' => [
                'sellerOperName',
                'commissionPercent',
                'acquiringPercent',
            ],
        ];

        return $this->postRequest($url, $apiKey, $payload);
    }

    public function getSalesFunnelProducts(string $apiKey, Carbon $startDate, Carbon $endDate, array $filters = [])
    {
        $url = 'https://seller-analytics-api.wildberries.ru/api/analytics/v3/sales-funnel/products';

        $payload = [
            'selectedPeriod' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'nmIds' => $filters['nmIds'] ?? [],
            'brandNames' => $filters['brandNames'] ?? [],
            'subjectIds' => $filters['subjectIds'] ?? [],
            'tagIds' => $filters['tagIds'] ?? [],
            'skipDeletedNm' => $filters['skipDeletedNm'] ?? false,
            'limit' => $filters['limit'] ?? 1000,
            'offset' => $filters['offset'] ?? 0,
        ];

        if (isset($filters['pastPeriod'])) {
            $payload['pastPeriod'] = $filters['pastPeriod'];
        }

        if (isset($filters['orderBy'])) {
            $payload['orderBy'] = $filters['orderBy'];
        }

        foreach ($payload as $key => $value) {
            if ($value === null) {
                unset($payload[$key]);
            }
        }

        return $this->postRequest($url, $apiKey, $payload);
    }

    public function parseApiResponse($resp, string $function = ''): array
    {
        $decode = static function ($raw) {
            if ($raw === '' || $raw === null) {
                return [];
            }

            $decoded = json_decode($raw, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
        };

        if (! is_array($resp)) {
            $success = false;
            $code = 503;
            $data = 'Ошибка доступа к API. Функция: ' . $function;
        } else {
            $payload = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : $resp;
            $code = $payload['code'] ?? 503;
            $rawResponse = $payload['response'] ?? '';

            switch ($code) {
                case 200:
                    $success = true;
                    $data = $decode($rawResponse);
                    break;
                case 204:
                    $success = true;
                    $data = [];
                    break;
                case 400:
                case 401:
                case 403:
                    $success = false;
                    $data = $decode($rawResponse);
                    break;
                case 422:
                    $success = false;
                    $data = $decode($rawResponse);
                    break;
                case 429:
                    $success = false;
                    $data = 'Превышен лимит запросов. Функция: ' . $function;
                    break;
                default:
                    $success = false;
                    $decoded = $decode($rawResponse);
                    $data = $decoded === [] ? 'Неизвестная ошибка API' : $decoded;
                    break;
            }
        }

        if (! $success) {
            $message = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
            Log::channel('wb_api_response')->info('Код ответа: ' . ($code ?? 'n/a') . '. Сообщение: ' . $message);
        }

        return ['success' => $success, 'code' => $code ?? 503, 'data' => $data];
    }
}
