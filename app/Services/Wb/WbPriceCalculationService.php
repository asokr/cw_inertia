<?php

namespace App\Services\Wb;

use App\Http\Traits\GuzzleTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class WbPriceCalculationService
{
    use GuzzleTrait;

    /** Content API cards/list personal: interval 600 ms. */
    private const CARDS_LIST_INTERVAL_MS = 600;

    /** Statistics supplier/sales personal: 1 req/min. */
    private const SALES_PAGE_INTERVAL_SECONDS = 60;

    private const NETWORK_RETRY_ATTEMPTS = 2;

    private const NETWORK_RETRY_SLEEP_SECONDS = 4;

    public function getAllCards(string $apiKey, array $params, array $cards = [])
    {
        $url = 'https://content-api.wildberries.ru/content/v2/get/cards/list?locale=ru';

        $result = $this->postRequestWithNetworkRetry($url, $apiKey, $params, 'getAllCards');

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

            // Personal limit: interval 600 ms between cards/list pages.
            usleep(self::CARDS_LIST_INTERVAL_MS * 1000);

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
        $isFirstPage = true;

        while (true) {
            if (! $isFirstPage) {
                // Personal limit for supplier/sales: 1 request per minute.
                sleep(self::SALES_PAGE_INTERVAL_SECONDS);
            }
            $isFirstPage = false;

            $params = [
                'dateFrom' => $dateFromStr,
                'flag' => $flag,
            ];

            $result = $this->getRequestWithNetworkRetry($url, $apiKey, $params, 'getSales');

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

        return $this->getRequestWithNetworkRetry($url, $apiKey, $params, 'getWhTariffs');
    }

    public function getWBTariffs(string $apiKey)
    {
        $url = 'https://common-api.wildberries.ru/api/v1/tariffs/commission';

        $params = [
            'locale' => 'ru',
        ];

        return $this->getRequestWithNetworkRetry($url, $apiKey, $params, 'getWBTariffs');
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

        return $this->postRequestWithNetworkRetry($url, $apiKey, $payload, 'getReportDetailByPeriod');
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

        return $this->postRequestWithNetworkRetry($url, $apiKey, $payload, 'getSalesFunnelProducts');
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
            $code = (int) ($payload['code'] ?? 503);
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
                case 504:
                case 0:
                    $success = false;
                    $data = $this->humanizeNetworkError($rawResponse, $function);
                    if ($code === 0 && $this->isTimeoutRaw($rawResponse)) {
                        $code = 504;
                    }
                    break;
                default:
                    $success = false;
                    if ($this->isTimeoutRaw($rawResponse)) {
                        $code = 504;
                        $data = $this->humanizeNetworkError($rawResponse, $function);
                    } else {
                        $decoded = $decode($rawResponse);
                        $data = $decoded === [] ? 'Неизвестная ошибка API' : $decoded;
                    }
                    break;
            }
        }

        if (! $success) {
            $message = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
            Log::channel('wb_api_response')->info('Код ответа: ' . ($code ?? 'n/a') . '. Сообщение: ' . $message);
        }

        return ['success' => $success, 'code' => $code ?? 503, 'data' => $data];
    }

    private function getRequestWithNetworkRetry(string $url, string $apiKey, array $params, string $function): array
    {
        $attempt = 0;
        $result = [];

        while ($attempt < self::NETWORK_RETRY_ATTEMPTS) {
            $attempt++;
            $result = $this->getRequest($url, $apiKey, $params, $function);

            if (! $this->isRetriableNetworkResult($result) || $attempt >= self::NETWORK_RETRY_ATTEMPTS) {
                return $result;
            }

            sleep(self::NETWORK_RETRY_SLEEP_SECONDS);
        }

        return $result;
    }

    private function postRequestWithNetworkRetry(string $url, string $apiKey, array $payload, string $function): array
    {
        $attempt = 0;
        $result = [];

        while ($attempt < self::NETWORK_RETRY_ATTEMPTS) {
            $attempt++;
            $result = $this->postRequest($url, $apiKey, $payload, $function);

            if (! $this->isRetriableNetworkResult($result) || $attempt >= self::NETWORK_RETRY_ATTEMPTS) {
                return $result;
            }

            sleep(self::NETWORK_RETRY_SLEEP_SECONDS);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isRetriableNetworkResult(array $result): bool
    {
        $code = (int) ($result['code'] ?? 0);
        $response = (string) ($result['response'] ?? '');

        if (in_array($code, [401, 403, 400, 422, 429], true)) {
            return false;
        }

        if ($code === 504 || $code === 0) {
            return true;
        }

        return $this->isTimeoutRaw($response);
    }

    private function isTimeoutRaw(mixed $raw): bool
    {
        if (! is_string($raw) || $raw === '') {
            return false;
        }

        $normalized = mb_strtolower($raw);

        return str_contains($normalized, 'timeout')
            || str_contains($normalized, 'timed out')
            || str_contains($normalized, 'curl error 28')
            || str_contains($normalized, 'operation timed out');
    }

    private function humanizeNetworkError(mixed $raw, string $function = ''): string
    {
        $suffix = $function !== '' ? " ({$function})" : '';

        if ($this->isTimeoutRaw($raw)) {
            return 'Сервер Wildberries не ответил вовремя. Данные импорта сохранены; повторите пересчёт чуть позже.'.$suffix;
        }

        if (is_string($raw) && trim($raw) !== '') {
            return 'Ошибка сети при обращении к API Wildberries.'.$suffix;
        }

        return 'Ошибка доступа к API.'.$suffix;
    }
}
