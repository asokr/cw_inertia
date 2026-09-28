<?php

namespace App\Services\Ozon;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Сериализация и retry для POST /v1/analytics/stocks.
 *
 * Метод на стороне Ozon узкий: 429 «rate limit per second» и 500 code=2
 * приходят даже при паузе в десятки секунд. Вызовы всех кабинетов идут через
 * один MySQL GET_LOCK, между запросами не меньше 20 с.
 */
class OzonAnalyticsStocksGate
{
    public const LOCK_NAME = 'ozon_analytics_stocks';

    public const MIN_INTERVAL_MS = 20_000;

    public const MAX_ATTEMPTS = 8;

    public const LOCK_WAIT_SECONDS = 120;

    /** @var list<int> */
    public const RETRY_BACKOFF_MS = [60_000, 90_000, 120_000, 180_000];

    private const LAST_REQUEST_CACHE_KEY = 'ozon:analytics-stocks:last';

    private bool $lockHeld = false;

    private int $requestCount = 0;

    private int $retryCount = 0;

    public function __construct(
        private readonly int $maxAttempts = self::MAX_ATTEMPTS,
        private readonly int $minIntervalMs = self::MIN_INTERVAL_MS,
    ) {}

    public function requestCount(): int
    {
        return $this->requestCount;
    }

    public function retryCount(): int
    {
        return $this->retryCount;
    }

    /**
     * @param  callable(): array{success?: bool, status?: int, data?: mixed}  $callback
     * @return array{success?: bool, status?: int, data?: mixed}
     */
    public function requestWithRetry(callable $callback, string $label = 'analytics/stocks'): array
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxAttempts) {
            $attempt++;

            try {
                $this->acquireSlot();
            } catch (Throwable $e) {
                $this->retryCount++;
                $lastError = $e->getMessage();
                if ($attempt >= $this->maxAttempts) {
                    throw new RuntimeException($lastError);
                }
                $this->sleepMs($this->backoffMs($attempt));
                continue;
            }

            $this->requestCount++;

            try {
                $response = $callback();
            } catch (Throwable $e) {
                $this->retryCount++;
                $lastError = $e->getMessage();
                $this->sleepMs($this->backoffMs($attempt));
                continue;
            } finally {
                $this->releaseSlot();
            }

            $status = (int) ($response['status'] ?? 0);
            $success = (bool) ($response['success'] ?? false);

            if ($success) {
                return $response;
            }

            $retryable = $status === 429 || $status >= 500 || $status === 0;
            if (! $retryable || $attempt >= $this->maxAttempts) {
                $message = (string) Arr::get(
                    $response,
                    'data.message',
                    Arr::get($response, 'data.error', "Ошибка Ozon API: {$label} (HTTP {$status})")
                );
                throw new RuntimeException(is_string($message) ? $message : "Ошибка Ozon API: {$label}");
            }

            $this->retryCount++;
            $this->sleepMs($this->backoffMs($attempt));
            $lastError = "HTTP {$status} on {$label}";
        }

        throw new RuntimeException($lastError ?: "Ошибка Ozon API: {$label}");
    }

    private function acquireSlot(): void
    {
        $this->lockHeld = false;

        if ($this->useMysqlLock()) {
            $got = 0;
            try {
                $rows = DB::select('SELECT GET_LOCK(?, ?) as l', [self::LOCK_NAME, self::LOCK_WAIT_SECONDS]);
                $got = (int) (is_object($rows[0] ?? null) ? ($rows[0]->l ?? 0) : 0);
            } catch (Throwable) {
                $got = 0;
            }

            if ($got !== 1) {
                throw new RuntimeException('Слот запроса остатков Ozon занят. Повторим попытку автоматически.');
            }

            $this->lockHeld = true;
        }

        $this->waitMinInterval();
        $this->markLastRequest();
    }

    private function releaseSlot(): void
    {
        if (! $this->lockHeld) {
            return;
        }

        try {
            DB::select('SELECT RELEASE_LOCK(?) as l', [self::LOCK_NAME]);
        } catch (Throwable) {
        }

        $this->lockHeld = false;
    }

    private function waitMinInterval(): void
    {
        if ($this->minIntervalMs <= 0 || $this->shouldSkipWaits()) {
            return;
        }

        $last = (float) Cache::get(self::LAST_REQUEST_CACHE_KEY, 0);
        if ($last <= 0) {
            return;
        }

        $elapsedMs = (microtime(true) - $last) * 1000;
        $waitMs = $this->minIntervalMs - $elapsedMs;
        if ($waitMs > 0) {
            $this->sleepMs((int) $waitMs);
        }
    }

    private function markLastRequest(): void
    {
        Cache::put(self::LAST_REQUEST_CACHE_KEY, microtime(true), 300);
    }

    private function backoffMs(int $attempt): int
    {
        $index = min(max($attempt - 1, 0), count(self::RETRY_BACKOFF_MS) - 1);

        return self::RETRY_BACKOFF_MS[$index];
    }

    private function sleepMs(int $ms): void
    {
        if ($ms <= 0 || $this->shouldSkipWaits()) {
            return;
        }

        usleep($ms * 1000);
    }

    private function useMysqlLock(): bool
    {
        if ($this->shouldSkipWaits()) {
            return false;
        }

        try {
            return DB::connection()->getDriverName() === 'mysql';
        } catch (Throwable) {
            return false;
        }
    }

    private function shouldSkipWaits(): bool
    {
        return app()->environment('testing');
    }
}
