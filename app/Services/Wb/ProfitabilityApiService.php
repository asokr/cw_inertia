<?php
// app/Services/Wb/ProfitabilityApiService.php

namespace App\Services\Wb;

use App\Http\Traits\GuzzleTrait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProfitabilityApiService
{
    use GuzzleTrait;

    private const REPORT_DETAIL_MIN_INTERVAL_SECONDS = 65;
    private const REPORT_DETAIL_MAX_429_RETRIES = 3;
    private const REPORT_DETAIL_LIMIT = 100000;

    private const REPORT_DETAIL_FIELDS = [
        'rrdId',
        'sellerOperName',
        'forPay',
        'retailAmount',
        'cashbackAmount',
        'cashbackDiscount',
        'quantity',
        'deliveryService',
        'bonusTypeName',
        'paidAcceptance',
        'penalty',
        'deduction',
        'paidStorage',
        'docTypeName',
        'nmId',
        'vendorCode',
        'techSize',
        'sku',
        'officeName',
    ];

    /**
     * Запрос финансового отчёта WB за период.
     */
    public function getReportDetailByPeriod(
        string $dateFrom,
        string $dateTo,
        object $cabinet,
        int $rrdid = 0,
        array $data = [],
        bool $accumulate = true,
        int $retryAttempt = 0
    ): array {
        $url = 'https://finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed';
        $payload = [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'limit' => self::REPORT_DETAIL_LIMIT,
            'rrdId' => $rrdid,
            'period' => 'daily',
            'fields' => self::REPORT_DETAIL_FIELDS,
        ];

        $this->throttleReportDetailRequest($cabinet);

        $resp = $this->postRequest($url, $cabinet->apikey, $payload, 'getReportDetailByPeriod');

        $rows = [];
        $nextRrdId = null;

        if (!$resp) {
            $success = false;
            $code = 503;
            $data = 'Ошибка доступа к API. Функция: getReportDetailByPeriod';
        } else {
            switch ((int) $resp['code']) {
                case 200:
                    $success = true;
                    $code = 200;
                    $decoded = json_decode($resp['response'], true);
                    $rows = $this->extractRows($decoded);

                    if ($accumulate) {
                        $data = array_merge($data, $rows);
                    } else {
                        $data = $rows;
                    }
                    break;
                case 204:
                    $success = true;
                    $code = 204;
                    $data = [];
                    break;
                case 400:
                case 401:
                case 403:
                case 422:
                    $success = false;
                    $code = (int) $resp['code'];
                    $data = json_decode($resp['response'], true) ?: $resp['response'];
                    break;
                case 429:
                    $success = false;
                    $code = 429;
                    $data = 'Превышен лимит запросов';

                    Log::warning('[ProfitabilityApiService] WB 429 rate limit', [
                        'function' => 'getReportDetailByPeriod',
                        'url' => $url,
                        'payload' => $payload,
                        'cabinet_id' => $cabinet->id ?? null,
                        'cabinet_name' => $cabinet->name ?? null,
                        'retry_attempt' => $retryAttempt,
                    ]);

                    if ($retryAttempt < self::REPORT_DETAIL_MAX_429_RETRIES) {
                        $accumulatedData = is_array($data) ? $data : [];

                        sleep(self::REPORT_DETAIL_MIN_INTERVAL_SECONDS);

                        return $this->getReportDetailByPeriod(
                            $dateFrom,
                            $dateTo,
                            $cabinet,
                            $rrdid,
                            $accumulatedData,
                            $accumulate,
                            $retryAttempt + 1
                        );
                    }
                    break;
                default:
                    $success = false;
                    $code = 503;
                    $data = 'Неизвестная ошибка API';
                    break;
            }
        }

        if (!$success) {
            $message = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($cabinet) {
                $message .= ' | Клиент: ' . $cabinet->id . ', ' . $cabinet->name;
            }

            Log::channel('wb_api_response')->info('Код ответа: ' . $code . '. Данные: ' . $message);
        }

        if (!empty($rows)) {
            Log::channel('wb_api_response')->info('Количество записей в отчёте: ' . count($rows));

            if ($success && count($rows) >= self::REPORT_DETAIL_LIMIT) {
                $last = end($rows);
                $nextRrdId = isset($last['rrdId'])
                    ? (int) $last['rrdId']
                    : (isset($last['rrd_id']) ? (int) $last['rrd_id'] : null);

                if ($accumulate && $nextRrdId !== null) {
                    sleep(self::REPORT_DETAIL_MIN_INTERVAL_SECONDS);

                    return $this->getReportDetailByPeriod(
                        $dateFrom,
                        $dateTo,
                        $cabinet,
                        $nextRrdId,
                        $data,
                        true
                    );
                }
            }
        }

        $response = ['success' => $success, 'code' => $code, 'data' => $data];

        if ($nextRrdId !== null) {
            $response['next_rrdid'] = $nextRrdId;
        }

        return $response;
    }

    private function extractRows(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }

        if (array_is_list($decoded)) {
            return $decoded;
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            if (array_is_list($decoded['data'])) {
                return $decoded['data'];
            }

            if (isset($decoded['data']['items']) && is_array($decoded['data']['items']) && array_is_list($decoded['data']['items'])) {
                return $decoded['data']['items'];
            }
        }

        if (isset($decoded['items']) && is_array($decoded['items']) && array_is_list($decoded['items'])) {
            return $decoded['items'];
        }

        if (isset($decoded['result']) && is_array($decoded['result'])) {
            if (array_is_list($decoded['result'])) {
                return $decoded['result'];
            }

            if (isset($decoded['result']['items']) && is_array($decoded['result']['items']) && array_is_list($decoded['result']['items'])) {
                return $decoded['result']['items'];
            }
        }

        return [];
    }

    private function throttleReportDetailRequest(object $cabinet): void
    {
        $cabinetId = (int) ($cabinet->id ?? 0);
        $scope = $cabinetId > 0 ? (string) $cabinetId : md5((string) ($cabinet->apikey ?? 'unknown'));
        $cacheKey = 'wb_profitability_report_detail_last_request_' . $scope;

        $lastRequestAt = (float) Cache::get($cacheKey, 0);
        $now = microtime(true);
        $elapsed = $now - $lastRequestAt;

        if ($elapsed < self::REPORT_DETAIL_MIN_INTERVAL_SECONDS) {
            $waitSeconds = (int) ceil(self::REPORT_DETAIL_MIN_INTERVAL_SECONDS - $elapsed);
            sleep(max(1, $waitSeconds));
            $now = microtime(true);
        }

        Cache::put($cacheKey, $now, now()->addMinutes(5));
    }
}
