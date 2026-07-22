<?php

namespace Tests\Unit;

use App\Services\Subscriber\Wb\WbFeedbacksService;
use Tests\TestCase;

class WbFeedbacksCountParseTest extends TestCase
{
    public function test_extract_count_from_nested_data_wrapper(): void
    {
        $service = new WbFeedbacksService();

        $this->assertSame(1234, $service->extractCountUnanswered([
            'data' => ['countUnanswered' => 1234, 'countUnansweredToday' => 3],
        ]));
    }

    public function test_extract_count_from_flat_payload(): void
    {
        $service = new WbFeedbacksService();

        $this->assertSame(50, $service->extractCountUnanswered([
            'countUnanswered' => 50,
        ]));
    }

    public function test_extract_count_returns_null_when_missing(): void
    {
        $service = new WbFeedbacksService();

        $this->assertNull($service->extractCountUnanswered(['data' => []]));
        $this->assertNull($service->extractCountUnanswered(null));
        $this->assertNull($service->extractCountUnanswered('err'));
    }
}
