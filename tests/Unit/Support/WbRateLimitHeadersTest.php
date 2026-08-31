<?php

namespace Tests\Unit\Support;

use App\Support\Wb\WbRateLimitHeaders;
use Tests\TestCase;

class WbRateLimitHeadersTest extends TestCase
{
    public function test_prefers_x_ratelimit_retry_regardless_of_case(): void
    {
        $seconds = WbRateLimitHeaders::retryAfterSeconds([
            'X-RateLimit-Retry' => ['90'],
            'Retry-After' => ['1'],
        ]);

        $this->assertSame(90, $seconds);
    }

    public function test_reads_guzzle_style_header_array(): void
    {
        $seconds = WbRateLimitHeaders::retryAfterSeconds([
            'X-Ratelimit-Retry' => ['25'],
        ]);

        $this->assertSame(25, $seconds);
    }

    public function test_falls_back_to_reset_when_retry_missing(): void
    {
        $seconds = WbRateLimitHeaders::retryAfterSeconds([
            'X-Ratelimit-Reset' => ['40'],
            'Retry-After' => ['1'],
        ]);

        $this->assertSame(40, $seconds);
    }

    public function test_retry_after_is_last_resort(): void
    {
        $seconds = WbRateLimitHeaders::retryAfterSeconds([
            'Retry-After' => '7',
        ]);

        $this->assertSame(7, $seconds);
    }

    public function test_parse_remaining_and_limit(): void
    {
        $parsed = WbRateLimitHeaders::parse([
            'x-ratelimit-remaining' => ['0'],
            'X-RateLimit-Limit' => ['1'],
        ]);

        $this->assertSame(0, $parsed['remaining']);
        $this->assertSame(1, $parsed['limit']);
        $this->assertNull($parsed['retry_after']);
    }
}
