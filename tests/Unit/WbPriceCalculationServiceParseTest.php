<?php

namespace Tests\Unit;

use App\Services\Wb\WbPriceCalculationService;
use Tests\TestCase;

class WbPriceCalculationServiceParseTest extends TestCase
{
    private WbPriceCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WbPriceCalculationService();
    }

    public function test_stream_timeout_is_humanized_and_mapped_to_504(): void
    {
        $result = $this->service->parseApiResponse([
            'code' => 0,
            'response' => 'stream timeout',
        ], 'getSales');

        $this->assertFalse($result['success']);
        $this->assertSame(504, $result['code']);
        $this->assertIsString($result['data']);
        $this->assertStringContainsString('не ответил вовремя', mb_strtolower($result['data']));
        $this->assertStringNotContainsString('stream timeout', mb_strtolower($result['data']));
    }

    public function test_explicit_504_timeout_message_is_humanized(): void
    {
        $result = $this->service->parseApiResponse([
            'code' => 504,
            'response' => 'cURL error 28: Operation timed out',
        ], 'getSalesFunnelProducts');

        $this->assertFalse($result['success']);
        $this->assertSame(504, $result['code']);
        $this->assertStringContainsString('wildberries', mb_strtolower((string) $result['data']));
    }

    public function test_429_keeps_rate_limit_message(): void
    {
        $result = $this->service->parseApiResponse([
            'code' => 429,
            'response' => 'too many',
        ], 'getSales');

        $this->assertFalse($result['success']);
        $this->assertSame(429, $result['code']);
        $this->assertStringContainsString('лимит', mb_strtolower((string) $result['data']));
    }

    public function test_200_still_decodes_json(): void
    {
        $result = $this->service->parseApiResponse([
            'code' => 200,
            'response' => json_encode(['cards' => [['nmID' => 1]]]),
        ], 'getAllCards');

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['code']);
        $this->assertSame(1, $result['data']['cards'][0]['nmID']);
    }
}
