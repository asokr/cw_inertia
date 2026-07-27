<?php

namespace App\Services\Wb;

use App\Http\Traits\WBadvTrait;
use App\Models\WbApiRequestLog;
use App\Models\WbApiUsageStat;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WbApiUsageService
{
    use WBadvTrait;

    protected const LEGAL_ENTITY_TTL_HOURS = 24;

    /** WB seller-info: personal limit is 1 request per minute. */
    protected const SELLER_INFO_MIN_INTERVAL_SECONDS = 60;

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

    /** Max raw response body size stored in logs (64 KB). */
    protected const RESPONSE_BODY_MAX_BYTES = 65536;

    public function recordRequest(
        ?string $apiKey,
        ?string $method = null,
        ?string $url = null,
        ?array $requestData = null,
        ?int $responseCode = null,
        mixed $responseBody = null,
    ): void {
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
        if ($this->shouldSyncLegalEntity($stat) && $this->acquireSellerInfoSyncSlot($hash)) {
            $this->syncLegalEntityData($stat, $apiKey);
        }

        // Записываем детальный лог запроса ПОСЛЕ синхронизации seller_id
        $this->logRequestDetails(
            $hash,
            $apiKey,
            $stat->seller_id,
            $method,
            $url,
            $requestData,
            $responseCode,
            $responseBody,
        );
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
        ?int $responseCode,
        mixed $responseBody = null,
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
                'response_data' => $this->normalizeResponseBody($responseBody),
                'response_code' => $responseCode,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('wb_api_response')->warning('Failed to log request details', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalize WB response body for JSON storage with size limit.
     *
     * @return array<string, mixed>|null
     */
    protected function normalizeResponseBody(mixed $responseBody): ?array
    {
        if ($responseBody === null) {
            return null;
        }

        if (is_array($responseBody)) {
            $encoded = json_encode($responseBody, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false) {
                return ['raw' => 'Unable to encode response array', 'truncated' => true];
            }

            if (strlen($encoded) <= static::RESPONSE_BODY_MAX_BYTES) {
                return $responseBody;
            }

            return [
                'raw' => mb_strcut($encoded, 0, static::RESPONSE_BODY_MAX_BYTES, 'UTF-8'),
                'truncated' => true,
                'original_bytes' => strlen($encoded),
            ];
        }

        if (! is_string($responseBody) && ! is_numeric($responseBody)) {
            return ['raw' => (string) json_encode($responseBody), 'truncated' => false];
        }

        $raw = (string) $responseBody;
        $truncated = false;
        $originalBytes = strlen($raw);

        if ($originalBytes > static::RESPONSE_BODY_MAX_BYTES) {
            $raw = mb_strcut($raw, 0, static::RESPONSE_BODY_MAX_BYTES, 'UTF-8');
            $truncated = true;
        }

        if ($raw === '') {
            return $truncated
                ? ['raw' => '', 'truncated' => true, 'original_bytes' => $originalBytes]
                : null;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && ! $truncated) {
            return $decoded;
        }

        $payload = ['raw' => $raw];
        if ($truncated) {
            $payload['truncated'] = true;
            $payload['original_bytes'] = $originalBytes;
        }

        return $payload;
    }

    /**
     * Sync at most once per TTL, even when legal_entity stayed empty after a failed attempt.
     * Previously empty legal_entity forced a seller-info call on every tracked request (429 storm).
     */
    protected function shouldSyncLegalEntity(WbApiUsageStat $stat): bool
    {
        if (! $stat->legal_entity_synced_at) {
            return true;
        }

        return $stat->legal_entity_synced_at->lte(now()->subHours(static::LEGAL_ENTITY_TTL_HOURS));
    }

    /**
     * Atomic per-token slot so parallel workers do not burst seller-info (limit ~1/min).
     */
    protected function acquireSellerInfoSyncSlot(string $apiKeyHash): bool
    {
        $cacheKey = 'wb:seller-info:sync:' . $apiKeyHash;

        return Cache::add($cacheKey, 1, now()->addSeconds(static::SELLER_INFO_MIN_INTERVAL_SECONDS));
    }

    protected function syncLegalEntityData(WbApiUsageStat $stat, string $apiKey): void
    {
        static::withoutTracking(function () use ($stat, $apiKey) {
            try {
                $response = $this->apiGetSellerInfo($apiKey);
                $parsed = $this->parseApiResponse($response);

                // Always mark attempt so failed sync (429/401/empty) does not retry every request.
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
                } else {
                    $code = (int) ($parsed['code'] ?? 0);

                    Log::channel('wb_api_response')->warning('WB API legal entity sync unsuccessful', [
                        'api_key_hash' => $stat->api_key_hash,
                        'code' => $code,
                    ]);
                }

                $stat->save();
            } catch (\Throwable $exception) {
                try {
                    $stat->legal_entity_synced_at = now();
                    $stat->save();
                } catch (\Throwable $saveException) {
                    // ignore secondary failure
                }

                Log::channel('wb_api_response')->error('WB API legal entity sync failed', [
                    'api_key_hash' => $stat->api_key_hash,
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
