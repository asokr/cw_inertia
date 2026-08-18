<?php

namespace Database\Seeders;

use App\Enums\Credits\CreditBillingMode;
use App\Enums\Credits\CreditServiceCode;
use App\Models\Credits\CreditService;
use App\Models\Credits\CreditSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class CreditPricingSeeder extends Seeder
{
    public function run(): void
    {
        if (
            ! Schema::hasTable('credit_settings')
            || ! Schema::hasTable('credit_services')
            || ! Schema::hasTable('credit_service_price_tiers')
        ) {
            return;
        }

        $this->seedRublesPerCredit();
        $this->seedServices();
    }

    private function seedRublesPerCredit(): void
    {
        CreditSetting::query()->firstOrCreate(
            ['key' => CreditSetting::RUBLES_PER_CREDIT],
            ['value' => '2.00'],
        );
    }

    private function seedServices(): void
    {
        $this->seedFixed(CreditServiceCode::GenerateText, 10, 1);
        $this->seedFixed(CreditServiceCode::FeedbackAnswer, 20, 1);

        $this->seedResolutionService(CreditServiceCode::GenerateImage, CreditBillingMode::ByResolution, 30, [
            ['default', 5, 10],
            ['1K', 10, 20],
            ['2K', 15, 30],
            ['4K', 20, 40],
        ]);

        $this->seedResolutionService(CreditServiceCode::EditImage, CreditBillingMode::ByResolution, 40, [
            ['default', 5, 10],
            ['1K', 10, 20],
            ['2K', 15, 30],
            ['4K', 20, 40],
        ]);

        $this->seedResolutionService(CreditServiceCode::GenerateVideo, CreditBillingMode::PerSecondByResolution, 50, [
            ['480p', 4, 10],
            ['720p', 8, 20],
        ]);
    }

    private function seedFixed(CreditServiceCode $code, int $sortOrder, int $amount): void
    {
        CreditService::query()->firstOrCreate(
            ['code' => $code->value],
            [
                'name' => $code->label(),
                'billing_mode' => CreditBillingMode::Fixed,
                'amount' => $amount,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ],
        );
    }

    /**
     * @param  array<int, array{0: string, 1: int, 2: int}>  $tiers
     */
    private function seedResolutionService(
        CreditServiceCode $code,
        CreditBillingMode $mode,
        int $sortOrder,
        array $tiers,
    ): void {
        $service = CreditService::query()->firstOrCreate(
            ['code' => $code->value],
            [
                'name' => $code->label(),
                'billing_mode' => $mode,
                'amount' => null,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ],
        );

        foreach ($tiers as [$paramValue, $amount, $tierOrder]) {
            $service->tiers()->firstOrCreate(
                [
                    'param_key' => 'resolution',
                    'param_value' => $paramValue,
                ],
                [
                    'amount' => $amount,
                    'sort_order' => $tierOrder,
                ],
            );
        }
    }
}
