<?php

namespace App\Services\Oz\StockHistory;

use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

/**
 * Retry и пауза между запросами к Seller API при сборе истории остатков.
 */
class OzStockHistoryRequestGuard
{
    private int $requestCount = 0;

    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly int $maxAttempts = 4,
        private readonly int $minIntervalMs = 350,
        private readonly int $rateLimitBackoffMs = 30_000,
    ) {}

    /**
     * @param  callable(): array{success?: bool, status?: int, data?: mixed}  $callback
     * @return array{success?: bool, status?: int, data?: mixed}
     */
    public function requestWithRetry(callable $callback, string $label): array
    {
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxAttempts) {
            $attempt++;
            $this->throttle();
            $this->requestCount++;

            try {
                $response = $callback();
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                $this->sleepMs(min(2000 * $attempt, 8000));
                continue;
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

            $sleepMs = $status === 429
                ? max($this->rateLimitBackoffMs, 2000 * $attempt)
                : 1000 * $attempt;
            $this->sleepMs($sleepMs);
            $lastError = "HTTP {$status} on {$label}";
        }

        throw new RuntimeException($lastError ?: "Ошибка Ozon API: {$label}");
    }

    private function throttle(): void
    {
        if ($this->minIntervalMs <= 0) {
            return;
        }

        $now = microtime(true);
        if ($this->lastRequestAt > 0) {
            $elapsedMs = ($now - $this->lastRequestAt) * 1000;
            $waitMs = $this->minIntervalMs - $elapsedMs;
            if ($waitMs > 0) {
                $this->sleepMs((int) $waitMs);
            }
        }
        $this->lastRequestAt = microtime(true);
    }

    private function sleepMs(int $ms): void
    {
        if ($ms <= 0 || app()->environment('testing')) {
            return;
        }

        usleep($ms * 1000);
    }
}
