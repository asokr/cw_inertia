<?php

namespace App\Services\Ozon;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ozon Performance API (рекламный кабинет).
 *
 * Официальная документация: https://docs.ozon.ru/api/performance/
 * Base: https://api-performance.ozon.ru (performance.ozon.ru deprecated с 15.01.2025)
 *
 * Auth: OAuth2 client_credentials → Bearer token.
 * Credentials: отдельная пара Client ID + Client Secret из
 * «Настройки → Performance API» (НЕ Seller API Client-Id + Api-Key).
 *
 * Seller API-ключ с ролями Admin/Analytics рекламу не открывает —
 * в разрешениях Seller API нет пункта «реклама», это другой продукт.
 */
class OzonPerformanceApiService
{
    private const BASE_URL = 'https://api-performance.ozon.ru/';

    private const MIN_INTERVAL_MS = 400;

    private float $lastRequestAt = 0.0;

    /**
     * @return array{success: bool, status: int, data: mixed}
     */
    public function getAccessToken(string $clientId, string $clientSecret): array
    {
        // client_id в ЛК иногда приходит с суффиксом @advertising.performance.ozon.ru
        $clientId = trim(str_replace('@advertising.performance.ozon.ru', '', $clientId));

        return $this->request(
            'POST',
            'api/client/token',
            [
                'json' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'client_credentials',
                ],
            ],
            bearer: null,
        );
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array{success: bool, status: int, data: mixed}
     */
    public function listCampaigns(string $accessToken, array $query = []): array
    {
        return $this->request('GET', 'api/client/campaign', [
            'query' => $query,
        ], $accessToken);
    }

    /**
     * Sync-отчёт «Оплата за клик» по кампании (CSV по умолчанию).
     * Это итоги кампании, без разреза по SKU — для join к товарам не подходит.
     *
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @return array{success: bool, status: int, data: mixed}
     */
    public function getCampaignProductStatistics(string $accessToken, array $query): array
    {
        return $this->request('GET', 'api/client/statistics/campaign/product', [
            'query' => $query,
        ], $accessToken);
    }

    /**
     * Sync JSON той же статистики (from/to RFC 3339 или dateFrom/dateTo).
     *
     * @param  array<string, scalar|list<scalar>|null>  $query
     * @return array{success: bool, status: int, data: mixed}
     */
    public function getCampaignProductStatisticsJson(string $accessToken, array $query): array
    {
        return $this->request('GET', 'api/client/statistics/campaign/product/json', [
            'query' => $query,
        ], $accessToken);
    }

    /**
     * Sync-статистика по SKU кампаний «Оплата за клик».
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status: int, data: mixed}
     */
    public function getProductSkuStatistics(string $accessToken, array $payload): array
    {
        return $this->request('POST', 'api/client/statistics/products/sku', [
            'json' => $payload,
        ], $accessToken);
    }

    /**
     * @return array{success: bool, status: int, data: mixed}
     */
    public function getCampaignObjects(string $accessToken, int|string $campaignId): array
    {
        return $this->request(
            'GET',
            'api/client/campaign/'.rawurlencode((string) $campaignId).'/objects',
            [],
            $accessToken,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status: int, data: mixed}
     */
    public function createCpcProductCampaign(string $accessToken, array $payload): array
    {
        return $this->request('POST', 'api/client/campaign/cpc/v2/product', [
            'json' => $payload,
        ], $accessToken);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status: int, data: mixed}
     */
    public function addCampaignProducts(string $accessToken, int|string $campaignId, array $payload): array
    {
        return $this->request(
            'POST',
            'api/client/campaign/'.rawurlencode((string) $campaignId).'/products',
            ['json' => $payload],
            $accessToken,
        );
    }

    /**
     * @return array{success: bool, status: int, data: mixed}
     */
    public function activateCampaign(string $accessToken, int|string $campaignId): array
    {
        return $this->request(
            'POST',
            'api/client/campaign/'.rawurlencode((string) $campaignId).'/activate',
            ['json' => new \stdClass],
            $accessToken,
        );
    }

    /**
     * @return array{success: bool, status: int, data: mixed}
     */
    public function deactivateCampaign(string $accessToken, int|string $campaignId): array
    {
        return $this->request(
            'POST',
            'api/client/campaign/'.rawurlencode((string) $campaignId).'/deactivate',
            ['json' => new \stdClass],
            $accessToken,
        );
    }

    /**
     * Async JSON-отчёт за произвольный from/to (RFC 3339).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status: int, data: mixed}
     */
    public function requestStatisticsJson(string $accessToken, array $payload): array
    {
        return $this->request('POST', 'api/client/statistics/json', [
            'json' => $payload,
        ], $accessToken);
    }

    /**
     * Async: запросить отчёт (fallback).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status: int, data: mixed}
     */
    public function requestStatistics(string $accessToken, array $payload): array
    {
        return $this->request('POST', 'api/client/statistics', [
            'json' => $payload,
        ], $accessToken);
    }

    /**
     * @return array{success: bool, status: int, data: mixed}
     */
    public function getStatisticsStatus(string $accessToken, string $uuid): array
    {
        return $this->request('GET', 'api/client/statistics/'.rawurlencode($uuid), [], $accessToken);
    }

    /**
     * @return array{success: bool, status: int, data: mixed}
     */
    public function downloadStatisticsReport(string $accessToken, string $uuid): array
    {
        return $this->request('GET', 'api/client/statistics/report', [
            'query' => ['UUID' => $uuid],
        ], $accessToken);
    }

    /**
     * Async JSON-отчёт по товарам в «Оплате за заказ» (выбранные товары).
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status: int, data: mixed}
     */
    public function generateSearchPromoProductsReportJson(string $accessToken, array $payload): array
    {
        return $this->request('POST', 'api/client/statistic/products/generate/json', [
            'json' => $payload,
        ], $accessToken);
    }

    /**
     * Async JSON-отчёт по товарам единой кампании «все товары / оплата за заказ».
     *
     * @param  array<string, scalar|null>  $query
     * @return array{success: bool, status: int, data: mixed}
     */
    public function generateAllSkuPromoProductsReportJson(string $accessToken, array $query): array
    {
        return $this->request('GET', 'api/client/statistics/all_sku_promo/products/generate/json', [
            'query' => $query,
        ], $accessToken);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{success: bool, status: int, data: mixed}
     */
    private function request(string $method, string $uri, array $options = [], ?string $bearer = null): array
    {
        $this->throttle();

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($bearer !== null && $bearer !== '') {
            $headers['Authorization'] = 'Bearer '.$bearer;
        }

        if (isset($options['query']) && is_array($options['query'])) {
            // Ozon ждёт campaignIds=a&campaignIds=b, а не campaignIds[0]=a (http_build_query).
            $options['query'] = $this->buildRepeatedQuery($options['query']);
        }

        $client = new \GuzzleHttp\Client([
            'base_uri' => self::BASE_URL,
            'headers' => $headers,
            'http_errors' => false,
            'timeout' => 60,
        ]);

        try {
            $response = $client->request($method, $uri, $options);
        } catch (Throwable $exception) {
            Log::channel('oz_api_response')->error('Ошибка обращения к Ozon Performance API', [
                'uri' => $uri,
                'message' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 503,
                'data' => ['message' => 'Не удалось обратиться к Ozon Performance API'],
            ];
        }

        $status = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // CSV-отчёты performance иногда приходят текстом
            if ($status >= 200 && $status < 300 && $body !== '') {
                return [
                    'success' => true,
                    'status' => $status,
                    'data' => ['raw' => $body],
                ];
            }

            Log::channel('oz_api_response')->warning('Некорректный ответ Ozon Performance API', [
                'uri' => $uri,
                'status' => $status,
                'body' => mb_substr($body, 0, 500),
            ]);

            return [
                'success' => false,
                'status' => $status,
                'data' => ['message' => 'Некорректный ответ Ozon Performance API'],
            ];
        }

        $success = $status >= 200 && $status < 300 && ! Arr::get($decoded, 'error');

        if (! $success) {
            Log::channel('oz_api_response')->info('Ошибка Ozon Performance API', [
                'uri' => $uri,
                'status' => $status,
                'response' => $decoded,
            ]);
        }

        return [
            'success' => $success,
            'status' => $status,
            'data' => $decoded,
        ];
    }

    /**
     * @param  array<string, scalar|list<scalar>|null>  $query
     */
    private function buildRepeatedQuery(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            $name = rawurlencode((string) $key);
            if (is_array($value)) {
                foreach ($value as $item) {
                    if ($item === null) {
                        continue;
                    }
                    $parts[] = $name.'='.rawurlencode((string) $item);
                }
                continue;
            }
            if ($value === null) {
                continue;
            }
            $parts[] = $name.'='.rawurlencode((string) $value);
        }

        return implode('&', $parts);
    }

    private function throttle(): void
    {
        $now = microtime(true);
        if ($this->lastRequestAt > 0) {
            $elapsedMs = ($now - $this->lastRequestAt) * 1000;
            $waitMs = self::MIN_INTERVAL_MS - $elapsedMs;
            if ($waitMs > 0) {
                usleep((int) ($waitMs * 1000));
            }
        }
        $this->lastRequestAt = microtime(true);
    }
}
