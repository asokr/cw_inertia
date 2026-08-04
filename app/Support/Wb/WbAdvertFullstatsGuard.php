<?php

namespace App\Support\Wb;

use Illuminate\Support\Facades\Cache;

/**
 * Token-bucket lite for WB GET /adv/v3/fullstats.
 *
 * Docs: Period 1 min, Limit 3, Interval 20 s, Burst 1 (personal/service).
 */
final class WbAdvertFullstatsGuard
{
    public const MIN_INTERVAL_SECONDS = 20;

    public const MAX_REQUESTS_PER_MINUTE = 3;

    public const DEFAULT_RETRY_AFTER_429 = 20;

    public function tokenScope(string $apiKey): string
    {
        return hash('sha256', trim($apiKey));
    }

    public function intervalKey(string $tokenScope): string
    {
        return "wb_fullstats:last:{$tokenScope}";
    }

    public function windowKey(string $tokenScope): string
    {
        return "wb_fullstats:window:{$tokenScope}";
    }

    public function cooldownKey(string $tokenScope): string
    {
        return "wb_fullstats:cooldown:{$tokenScope}";
    }

    /**
     * Seconds to wait before the next fullstats call is allowed (0 = ready).
     */
    public function waitSeconds(string $apiKey): int
    {
        $scope = $this->tokenScope($apiKey);
        $now = time();

        $cooldownUntil = (int) Cache::get($this->cooldownKey($scope), 0);
        $wait = max(0, $cooldownUntil - $now);

        $lastAt = (int) Cache::get($this->intervalKey($scope), 0);
        if ($lastAt > 0) {
            $intervalWait = self::MIN_INTERVAL_SECONDS - ($now - $lastAt);
            if ($intervalWait > $wait) {
                $wait = $intervalWait;
            }
        }

        $timestamps = $this->readWindow($scope);
        if (count($timestamps) >= self::MAX_REQUESTS_PER_MINUTE) {
            $oldest = $timestamps[0];
            $windowWait = 60 - ($now - $oldest);
            if ($windowWait > $wait) {
                $wait = $windowWait;
            }
        }

        return max(0, $wait);
    }

    /**
     * Record a fullstats attempt (success or fail) for interval + window accounting.
     */
    public function markAttempt(string $apiKey): void
    {
        $scope = $this->tokenScope($apiKey);
        $now = time();

        Cache::put($this->intervalKey($scope), $now, 120);

        $timestamps = $this->readWindow($scope);
        $timestamps[] = $now;
        $timestamps = array_values(array_filter(
            $timestamps,
            static fn (int $ts): bool => $ts >= ($now - 60),
        ));
        // Keep only the newest window slice.
        if (count($timestamps) > self::MAX_REQUESTS_PER_MINUTE) {
            $timestamps = array_slice($timestamps, -self::MAX_REQUESTS_PER_MINUTE);
        }
        Cache::put($this->windowKey($scope), $timestamps, 120);
    }

    public function setCooldownAfter429(string $apiKey, ?int $retryAfterSeconds = null): void
    {
        $scope = $this->tokenScope($apiKey);
        $seconds = max(1, $retryAfterSeconds ?? self::DEFAULT_RETRY_AFTER_429);
        $until = time() + $seconds;
        Cache::put($this->cooldownKey($scope), $until, $seconds + 5);
    }

    /**
     * @return list<int>
     */
    private function readWindow(string $tokenScope): array
    {
        $raw = Cache::get($this->windowKey($tokenScope), []);
        if (! is_array($raw)) {
            return [];
        }

        $now = time();
        $out = [];
        foreach ($raw as $ts) {
            $t = (int) $ts;
            if ($t >= ($now - 60)) {
                $out[] = $t;
            }
        }

        sort($out);

        return $out;
    }
}
