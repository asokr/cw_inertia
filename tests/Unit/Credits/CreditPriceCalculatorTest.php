<?php

namespace Tests\Unit\Credits;

use App\Enums\Credits\CreditBillingMode;
use App\Enums\Credits\CreditServiceCode;
use App\Exceptions\Credits\CreditPriceNotFoundException;
use App\Models\Credits\CreditService;
use App\Models\Credits\CreditSetting;
use App\Services\Credits\CreditPriceCalculator;
use Database\Seeders\CreditPricingSeeder;
use Tests\Support\CreatesCreditBillingSchema;
use Tests\TestCase;

class CreditPriceCalculatorTest extends TestCase
{
    use CreatesCreditBillingSchema;

    private CreditPriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupCreditBillingSchema();
        $this->calculator = app(CreditPriceCalculator::class);
        (new CreditPricingSeeder())->run();
    }

    public function test_fixed_text_costs_one_credit(): void
    {
        $quote = $this->calculator->quote(CreditServiceCode::GenerateText->value);

        $this->assertSame(1, $quote->amount);
        $this->assertSame(CreditBillingMode::Fixed->value, $quote->billingMode);
    }

    public function test_image_1k_costs_ten_credits(): void
    {
        $quote = $this->calculator->quote(CreditServiceCode::GenerateImage->value, [
            'resolution' => '1k',
        ]);

        $this->assertSame(10, $quote->amount);
        $this->assertSame('1K', $quote->params['resolution']);
    }

    public function test_video_five_seconds_720p_costs_forty(): void
    {
        $quote = $this->calculator->quote(CreditServiceCode::GenerateVideo->value, [
            'resolution' => '720p',
            'duration' => 5,
        ]);

        $this->assertSame(40, $quote->amount);
        $this->assertSame(8, $quote->unitAmount);
    }

    public function test_unknown_resolution_throws(): void
    {
        $this->expectException(CreditPriceNotFoundException::class);

        $this->calculator->quote(CreditServiceCode::GenerateImage->value, [
            'resolution' => '8K',
        ]);
    }

    public function test_rubles_per_credit_comes_from_setting(): void
    {
        $this->assertSame('2.00', $this->calculator->rublesPerCredit());
        $this->assertSame('200.00', $this->calculator->purchaseCost(100));

        CreditSetting::query()
            ->where('key', CreditSetting::RUBLES_PER_CREDIT)
            ->update(['value' => '1.50']);

        $this->assertSame('1.50', $this->calculator->rublesPerCredit());
        $this->assertSame('150.00', $this->calculator->purchaseCost(100));
    }

    public function test_seeder_is_idempotent_and_keeps_admin_prices(): void
    {
        CreditService::query()
            ->where('code', CreditServiceCode::GenerateText->value)
            ->update(['amount' => 7]);

        CreditSetting::query()
            ->where('key', CreditSetting::RUBLES_PER_CREDIT)
            ->update(['value' => '3.00']);

        (new CreditPricingSeeder())->run();
        (new CreditPricingSeeder())->run();

        $this->assertSame(1, CreditService::query()->where('code', CreditServiceCode::GenerateText->value)->count());
        $this->assertSame(7, (int) CreditService::query()->where('code', CreditServiceCode::GenerateText->value)->value('amount'));
        $this->assertSame('3.00', CreditSetting::query()->where('key', CreditSetting::RUBLES_PER_CREDIT)->value('value'));
    }
}
