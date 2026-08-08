<?php

namespace App\Services\Ozon;

use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class OzonApiService
{
    private const BASE_URL = 'https://api-seller.ozon.ru/';

    public function getProductsList(string $apiKey, string $clientId, array $payload): array
    {
        return $this->post('v3/product/list', $apiKey, $clientId, $payload);
    }

    public function getProductsInfo(string $apiKey, string $clientId, array $productIds): array
    {
        $payload = ['product_id' => array_values($productIds)];

        return $this->post('v3/product/info/list', $apiKey, $clientId, $payload);
    }

    public function getAnalyticsAverageDeliveryTime(string $apiKey, string $clientId, array $payload): array
    {
        return $this->post('v1/analytics/average-delivery-time/summary', $apiKey, $clientId, $payload);
    }

    /**
     * Аналитика кабинета: POST /v1/analytics/data
     *
     * Free metrics (без Premium): revenue, ordered_units.
     * Premium-метрики (hits_view*, hits_tocart*, session_*, conv_*, returns, cancellations,
     * delivered_units, position_category) — не используем: недоступны стандартному продавцу.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status: int, data: mixed}
     */
    public function getAnalyticsData(string $apiKey, string $clientId, array $payload): array
    {
        return $this->post('v1/analytics/data', $apiKey, $clientId, $payload);
    }

    /**
     * Поисковые запросы по товарам: POST /v1/analytics/product-queries
     * Полная аналитика — Premium; free-поля частично доступны.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status: int, data: mixed}
     */
    public function getProductQueries(string $apiKey, string $clientId, array $payload): array
    {
        return $this->post('v1/analytics/product-queries', $apiKey, $clientId, $payload);
    }

    /**
     * Остатки на складах Ozon: POST /v2/analytics/stock_on_warehouses
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status: int, data: mixed}
     */
    public function getStocksOnWarehouses(string $apiKey, string $clientId, array $payload): array
    {
        return $this->post('v2/analytics/stock_on_warehouses', $apiKey, $clientId, $payload);
    }

    /**
     * Оборачиваемость / IDC: POST /v1/analytics/turnover/stocks
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, status: int, data: mixed}
     */
    public function getProductTurnover(string $apiKey, string $clientId, array $payload): array
    {
        return $this->post('v1/analytics/turnover/stocks', $apiKey, $clientId, $payload);
    }

    /**
     * @param  string  $apiKey
     * @param  string  $clientId
     * @param  array  $payload  (filter, last_id, limit, sort_by, sort_dir)
     * @return array
     */
    public function getProductAttributes(string $apiKey, string $clientId, array $payload): array
    {
        return $this->post('v4/product/info/attributes', $apiKey, $clientId, $payload);
    }

    public function post(string $uri, string $apiKey, string $clientId, array $payload = []): array
    {
        $client = new Client([
            'base_uri' => self::BASE_URL,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Client-Id' => $clientId,
                'Api-Key' => $apiKey,
            ],
            'http_errors' => false,
        ]);

        try {
            $response = $client->post($uri, empty($payload) ? [] : ['json' => $payload]);
        } catch (\Throwable $exception) {
            Log::channel('oz_api_response')->error('Ошибка обращения к API Ozon', [
                'uri' => $uri,
                'message' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 503,
                'data' => ['message' => 'Не удалось обратиться к API Ozon'],
            ];
        }

        $status = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::channel('oz_api_response')->warning('Некорректный ответ API Ozon', [
                'uri' => $uri,
                'status' => $status,
                'body' => $body,
            ]);

            return [
                'success' => false,
                'status' => $status,
                'data' => ['message' => 'Некорректный ответ API Ozon'],
            ];
        }

        $success = $status >= 200 && $status < 300 && ! Arr::get($decoded, 'error');

        if (! $success) {
            Log::channel('oz_api_response')->info('Ошибка API Ozon', [
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
}
