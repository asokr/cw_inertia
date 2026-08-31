<?php

namespace App\Support\Wb;

/**
 * Заголовки лимитов WB API. Регистр имени в ответе не гарантирован
 * (X-Ratelimit-Retry vs X-RateLimit-Retry).
 *
 * @see https://dev.wildberries.ru/knowledge-base/articles/019d49a1-28ca-7735-bf2f-98210695abc7/wb-api-rate-limits
 */
final class WbRateLimitHeaders
{
    /**
     * @param  array<string, mixed>|null  $headers  Guzzle getHeaders()
     * @return array{retry_after: int|null, reset: int|null, remaining: int|null, limit: int|null}
     */
    public static function parse(?array $headers): array
    {
        $map = self::normalize($headers);

        return [
            'retry_after' => self::firstInt($map, ['x-ratelimit-retry', 'x-rate-limit-retry']),
            'reset' => self::firstInt($map, ['x-ratelimit-reset', 'x-rate-limit-reset']),
            'remaining' => self::firstInt($map, ['x-ratelimit-remaining', 'x-rate-limit-remaining']),
            'limit' => self::firstInt($map, ['x-ratelimit-limit', 'x-rate-limit-limit']),
        ];
    }

    /**
     * Секунды до следующей попытки: X-RateLimit-Retry, иначе Reset, иначе Retry-After.
     *
     * Retry-After берём последним: прокси иногда отдают 1, а реальная пауза WB — в X-RateLimit-Retry.
     */
    public static function retryAfterSeconds(?array $headers): ?int
    {
        $parsed = self::parse($headers);
        if ($parsed['retry_after'] !== null) {
            return $parsed['retry_after'];
        }
        if ($parsed['reset'] !== null) {
            return $parsed['reset'];
        }

        $map = self::normalize($headers);

        return self::firstInt($map, ['retry-after']);
    }

    /**
     * @param  array<string, mixed>|null  $headers
     * @return array<string, mixed>
     */
    private static function normalize(?array $headers): array
    {
        if ($headers === null || $headers === []) {
            return [];
        }

        $out = [];
        foreach ($headers as $name => $value) {
            $out[strtolower((string) $name)] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $map
     * @param  list<string>  $names
     */
    private static function firstInt(array $map, array $names): ?int
    {
        foreach ($names as $name) {
            if (! array_key_exists($name, $map)) {
                continue;
            }
            $value = $map[$name];
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return null;
    }
}
