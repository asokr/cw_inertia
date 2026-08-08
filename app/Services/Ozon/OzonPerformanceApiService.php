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
     * Sync-статистика по товарам кампаний (SKU-level).
     * Предпочтительный метод для агрегации в snapshot.
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
