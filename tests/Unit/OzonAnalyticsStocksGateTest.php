<?php

namespace Tests\Unit;

use App\Services\Ozon\OzonAnalyticsStocksGate;
use RuntimeException;
use Tests\TestCase;

class OzonAnalyticsStocksGateTest extends TestCase
{
    public function test_retries_429_then_returns_success(): void
    {
        $gate = new OzonAnalyticsStocksGate();
        $calls = 0;

        $response = $gate->requestWithRetry(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return [
                    'success' => false,
                    'status' => 429,
                    'data' => ['code' => 8, 'message' => 'You have reached request rate limit per second'],
                ];
            }

            return [
                'success' => true,
                'status' => 200,
                'data' => ['items' => []],
            ];
        });

        $this->assertTrue($response['success']);
        $this->assertSame(2, $calls);
        $this->assertSame(2, $gate->requestCount());
        $this->assertSame(1, $gate->retryCount());
    }

    public function test_retries_500_like_rate_limit(): void
    {
        $gate = new OzonAnalyticsStocksGate();
        $calls = 0;

        $response = $gate->requestWithRetry(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return [
                    'success' => false,
                    'status' => 500,
                    'data' => ['code' => 2],
                ];
            }

            return [
                'success' => true,
                'status' => 200,
                'data' => ['items' => []],
            ];
        });

        $this->assertTrue($response['success']);
        $this->assertSame(2, $calls);
    }

    public function test_gives_up_after_max_attempts_on_429(): void
    {
        $gate = new OzonAnalyticsStocksGate(maxAttempts: 3);
        $calls = 0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('You have reached request rate limit per second');

        try {
            $gate->requestWithRetry(function () use (&$calls) {
                $calls++;

                return [
                    'success' => false,
                    'status' => 429,
                    'data' => ['code' => 8, 'message' => 'You have reached request rate limit per second'],
                ];
            });
        } finally {
            $this->assertSame(3, $calls);
        }
    }

    public function test_does_not_retry_client_error(): void
    {
        $gate = new OzonAnalyticsStocksGate();
        $calls = 0;

        $this->expectException(RuntimeException::class);

        try {
            $gate->requestWithRetry(function () use (&$calls) {
                $calls++;

                return [
                    'success' => false,
                    'status' => 400,
                    'data' => ['message' => 'bad request'],
                ];
            });
        } finally {
            $this->assertSame(1, $calls);
        }
    }
}
