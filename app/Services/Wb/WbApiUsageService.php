<?php

namespace App\Services\Wb;

use App\Http\Traits\WBadvTrait;
use App\Models\WbApiRequestLog;
use App\Models\WbApiUsageStat;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WbApiUsageService
{
    use WBadvTrait;

    protected const LEGAL_ENTITY_TTL_HOURS = 24;

    protected static bool $trackingDisabled = false;

    public static function isTrackingDisabled(): bool
    {
        return static::$trackingDisabled;
    }

    public static function withoutTracking(callable $callback)
    {
        $previous = static::$trackingDisabled;
        static::$trackingDisabled = true;

        try {
            return $callback();
        } finally {
            static::$trackingDisabled = $previous;
        }
    }

    public function recordRequest(?string $apiKey, ?string $method = null, ?string $url = null, ?array $requestData = null, ?int $responseCode = null): void
    {
        if (static::isTrackingDisabled()) {
            return;
        }

        $apiKey = $apiKey !== null ? trim($apiKey) : '';

        if ($apiKey === '') {
            return;
        }

        $hash = hash('sha256', $apiKey);
        $statDate = now()->toDateString();

        $stat = null;

        DB::transaction(function () use (&$stat, $hash, $statDate, $apiKey) {
            $stat = WbApiUsageStat::query()
                ->where('api_key_hash', $hash)
                ->whereDate('stat_date', $statDate)
                ->lockForUpdate()
                ->first();

            if (! $stat) {
                $stat = new WbApiUsageStat([
                    'api_key_hash' => $hash,
                    'stat_date' => $statDate,
                    'api_key' => $apiKey,
                    'requests_count' => 0,
                ]);
            } elseif (! $stat->api_key) {
                $stat->api_key = $apiKey;
            }

            $stat->incrementRequest();
            $stat->save();
        });

        if (! $stat) {
            return;
        }

        if ($stat->exists) {
            $stat->refresh();
        }

        // Синхронизируем данные о продавце ПЕРЕД записью лога
        if ($this->shouldSyncLegalEntity($stat)) {
            $this->syncLegalEntityData($stat, $apiKey);
        }

        // Записываем детальный лог запроса ПОСЛЕ синхронизации seller_id
        $this->logRequestDetails($hash, $apiKey, $stat->seller_id, $method, $url, $requestData, $responseCode);
    }

    /**
     * Записывает детальную информацию о запросе
     */
    protected function logRequestDetails(
        string $hash,
        string $apiKey,
        ?string $sellerId,
        ?string $method,
        ?string $url,
        ?array $requestData,
        ?int $responseCode
    ): void {
        try {
            // Извлекаем endpoint из URL (убираем query string и домен)
            $endpoint = $url;
            if ($url) {
                $parsed = parse_url($url);
                $endpoint = $parsed['path'] ?? $url;
            }

            WbApiRequestLog::create([
                'seller_id' => $sellerId,
                'api_key_hash' => $hash,
                'api_key' => $apiKey,
                'method' => $method ? strtoupper($method) : null,
                'endpoint' => $endpoint,
                'request_data' => $requestData,
                'response_code' => $responseCode,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('wb_api_response')->warning('Failed to log request details', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function shouldSyncLegalEntity(WbApiUsageStat $stat): bool
    {
        if (! $stat->legal_entity) {
            return true;
        }

        if (! $stat->legal_entity_synced_at) {
            return true;
        }

        return $stat->legal_entity_synced_at->lte(now()->subHours(static::LEGAL_ENTITY_TTL_HOURS));
    }

    protected function syncLegalEntityData(WbApiUsageStat $stat, string $apiKey): void
    {
        static::withoutTracking(function () use ($stat, $apiKey) {
            try {
                $response = $this->apiGetSellerInfo($apiKey);
                $parsed = $this->parseApiResponse($response);

                $stat->legal_entity_synced_at = now();

                if ($parsed['success'] ?? false) {
                    $payload = Arr::get($parsed, 'data', []);

                    if (is_array($payload)) {
                        $legalEntity = $this->extractLegalEntity($payload);
                        $sellerId = $this->extractSellerId($payload);

                        if ($legalEntity) {
                            $stat->legal_entity = $legalEntity;
                        }

                        if ($sellerId) {
                            $stat->seller_id = $sellerId;
                        }
                    }
                }

                $stat->save();
            } catch (\Throwable $exception) {
                Log::channel('wb_api_response')->error('WB API legal entity sync failed', [
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }

    protected function extractLegalEntity(array $payload): ?string
    {
        $name = Arr::get($payload, 'name');

        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        $candidates = [
            'legalEntity',
            'legal_entity',
            'legalName',
            'legal_name',
            'organization.name',
            'organization.legal_entity',
            'company.name',
        ];

        foreach ($candidates as $key) {
            $value = Arr::get($payload, $key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    protected function extractSellerId(array $payload): ?string
    {
        $sid = Arr::get($payload, 'sid');

        if (is_string($sid) && trim($sid) !== '') {
            return trim($sid);
        }

        $candidates = [
            'supplierId',
            'sellerId',
            'supplierID',
            'sellerID',
            'id',
            'organization.id',
        ];

        foreach ($candidates as $key) {
            $value = Arr::get($payload, $key);

            if ($value === null) {
                continue;
            }

            if (is_scalar($value)) {
                $value = (string) $value;
            }

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
