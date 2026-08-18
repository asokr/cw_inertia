<?php

namespace Tests\Unit\Credits;

use App\Models\Credits\CreditService;
use App\Services\Credits\LegacyAiLimitConverter;
use App\Support\ToolLimits;
use Database\Seeders\CreditPricingSeeder;
use Tests\Support\CreatesCreditBillingSchema;
use Tests\TestCase;

class LegacyAiLimitConverterTest extends TestCase
{
    use CreatesCreditBillingSchema;

    private LegacyAiLimitConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCreditBillingSchema();
        (new CreditPricingSeeder)->run();
        $this->converter = app(LegacyAiLimitConverter::class);
    }

    public function test_converts_each_ai_key_via_catalog_quote(): void
    {
        $result = $this->converter->convert([
            'feedbacks_gpt_query' => 3,
            'ai_text_query' => 10,
            'ai_image_query' => 2,
            'ai_video_query' => 5,
            'wb_cabinets' => 4,
        ]);

        $this->assertSame(1, $result->unitPrices['feedbacks_gpt_query']);
        $this->assertSame(1, $result->unitPrices['ai_text_query']);
        $this->assertSame(5, $result->unitPrices['ai_image_query']);
        $this->assertSame(4, $result->unitPrices['ai_video_query']);
        $this->assertSame(3 + 10 + 10 + 20, $result->total);
        $this->assertArrayNotHasKey('wb_cabinets', $result->breakdown);
    }

    public function test_uses_live_catalog_amount_not_hardcoded(): void
    {
        CreditService::query()->where('code', 'generate_text')->update(['amount' => 3]);

        $result = app(LegacyAiLimitConverter::class)->convert([
            'ai_text_query' => 10,
        ]);

        $this->assertSame(30, $result->total);
        $this->assertSame(3, $result->unitPrices['ai_text_query']);
    }

    public function test_skips_unlimited_sentinel(): void
    {
        $result = $this->converter->convert([
            'ai_text_query' => ToolLimits::UNLIMITED_VALUE,
            'ai_image_query' => 2,
        ]);

        $this->assertSame(10, $result->total);
        $this->assertSame(['ai_text_query'], $result->skippedUnlimited);
        $this->assertSame(0, $result->units['ai_text_query']);
    }

    public function test_empty_limits_give_zero(): void
    {
        $result = $this->converter->convert([]);

        $this->assertSame(0, $result->total);
        $this->assertSame([], $result->skippedUnlimited);
    }
}
