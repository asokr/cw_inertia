<?php

namespace Tests\Unit\Credits;

use App\Exceptions\Credits\CreditPriceNotFoundException;
use App\Models\Credits\AiCabinetAnalyzerCreditTariff;
use App\Services\Credits\AiCabinetAnalyzerCreditCalculator;
use Tests\Support\CreatesCreditBillingSchema;
use Tests\TestCase;

class AiCabinetAnalyzerCreditCalculatorTest extends TestCase
{
    use CreatesCreditBillingSchema;

    private AiCabinetAnalyzerCreditCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupCreditBillingSchema();
        $this->calculator = app(AiCabinetAnalyzerCreditCalculator::class);

        AiCabinetAnalyzerCreditTariff::query()->create([
            'provider' => 'gemini',
            'model' => 'gemini-3.1-pro-preview',
            'input_credits_per_1k' => '0.030000',
            'output_credits_per_1k' => '0.180000',
            'coefficient' => '1.0000',
            'is_default' => true,
            'is_active' => true,
        ]);

        AiCabinetAnalyzerCreditTariff::query()->create([
            'provider' => 'gpt',
            'model' => 'gpt-4.1',
            'input_credits_per_1k' => '0.040000',
            'output_credits_per_1k' => '0.240000',
            'coefficient' => '1.0000',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_quotes_large_cabinet_and_ceils(): void
    {
        $quote = $this->calculator->quoteCalls([
            [
                'provider' => 'gemini',
                'model' => 'gemini-3.1-pro-preview',
                'input_tokens' => 300000,
                'output_tokens' => 20000,
            ],
        ]);

        // 300*0.03 + 20*0.18 = 9 + 3.6 = 12.6 → 13
        $this->assertSame(13, $quote->amount);
        $this->assertSame('gemini', $quote->snapshot['provider']);
        $this->assertSame('ceil', $quote->snapshot['rounding']);
    }

    public function test_applies_coefficient_and_minimum_one_credit(): void
    {
        AiCabinetAnalyzerCreditTariff::query()
            ->where('provider', 'gemini')
            ->update(['coefficient' => '2.0000']);

        $quote = $this->calculator->quoteCalls([
            [
                'provider' => 'gemini',
                'model' => 'gemini-3.1-pro-preview',
                'input_tokens' => 1000,
                'output_tokens' => 0,
            ],
        ]);

        $this->assertSame(1, $quote->amount);
    }

    public function test_falls_back_to_provider_default_model(): void
    {
        $quote = $this->calculator->quoteCalls([
            [
                'provider' => 'gemini',
                'model' => 'gemini-unknown-preview',
                'input_tokens' => 100000,
                'output_tokens' => 0,
            ],
        ]);

        $this->assertTrue($quote->snapshot['calls'][0]['matched_default']);
        $this->assertSame(3, $quote->amount);
    }

    public function test_sums_mixed_providers(): void
    {
        $quote = $this->calculator->quoteCalls([
            [
                'provider' => 'gemini',
                'model' => 'gemini-3.1-pro-preview',
                'input_tokens' => 100000,
                'output_tokens' => 0,
            ],
            [
                'provider' => 'gpt',
                'model' => 'gpt-4.1',
                'input_tokens' => 100000,
                'output_tokens' => 0,
            ],
        ]);

        $this->assertSame('mixed', $quote->snapshot['provider']);
        // 3 + 4 = 7
        $this->assertSame(7, $quote->amount);
    }

    public function test_reserve_applies_safety_multiplier(): void
    {
        $actual = $this->calculator->quoteCalls([
            [
                'provider' => 'gemini',
                'model' => 'gemini-3.1-pro-preview',
                'input_tokens' => 300000,
                'output_tokens' => 20000,
            ],
        ]);
        $reserve = $this->calculator->quoteReserve([
            [
                'provider' => 'gemini',
                'model' => 'gemini-3.1-pro-preview',
                'input_tokens' => 300000,
                'output_tokens' => 20000,
            ],
        ]);

        $this->assertGreaterThanOrEqual($actual->amount, $reserve->amount);
        $this->assertTrue($reserve->snapshot['for_reserve']);
    }

    public function test_missing_tariff_throws(): void
    {
        AiCabinetAnalyzerCreditTariff::query()->delete();

        $this->expectException(CreditPriceNotFoundException::class);

        $this->calculator->quoteCalls([
            [
                'provider' => 'gemini',
                'model' => 'gemini-3.1-pro-preview',
                'input_tokens' => 1000,
                'output_tokens' => 100,
            ],
        ]);
    }
}
