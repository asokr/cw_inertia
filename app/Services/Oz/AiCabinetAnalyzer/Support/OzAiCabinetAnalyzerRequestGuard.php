<?php

namespace App\Services\Oz\AiCabinetAnalyzer\Support;

use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

/**
 * Общий retry/throttle для коллекторов Ozon AI Cabinet Analyzer.
 */
class OzAiCabinetAnalyzerRequestGuard
{
    private int $requestCount = 0;

    private int $retryCount = 0;

    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly int $maxAttempts = 4,
        private readonly int $minIntervalMs = 350,
        private readonly int $rateLimitBackoffMs = 60_000,
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
                $this->retryCount++;
                usleep(min(2_000_000 * $attempt, 8_000_000));
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
                    Arr::get($response, 'data.error', "Ozon API error on {$label} (HTTP {$status})")
                );
                throw new RuntimeException(is_string($message) ? $message : "Ozon API error on {$label}");
            }

            $this->retryCount++;
            $sleepMs = $status === 429
                ? max($this->rateLimitBackoffMs, 2000 * $attempt)
                : 1000 * $attempt;
            usleep($sleepMs * 1000);
            $lastError = "HTTP {$status} on {$label}";
        }

        throw new RuntimeException($lastError ?: "Ozon API failed: {$label}");
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
                usleep((int) ($waitMs * 1000));
            }
        }
        $this->lastRequestAt = microtime(true);
    }
}
