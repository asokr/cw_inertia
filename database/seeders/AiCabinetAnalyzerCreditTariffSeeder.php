<?php

namespace Database\Seeders;

use App\Models\Credits\AiCabinetAnalyzerCreditTariff;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class AiCabinetAnalyzerCreditTariffSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('ai_cabinet_analyzer_credit_tariffs')) {
            return;
        }

        $this->seedTariff(
            provider: AiCabinetAnalyzerCreditTariff::PROVIDER_GEMINI,
            model: (string) config('services.gemini.pro_model', 'gemini-3.1-pro-preview'),
            input: '0.5',
            output: '1.2',
            isDefault: true,
        );

        $this->seedTariff(
            provider: AiCabinetAnalyzerCreditTariff::PROVIDER_GPT,
            model: (string) config('services.gpt.model', 'gpt-4.1'),
            input: '0.01',
            output: '0.24',
            isDefault: true,
        );
    }

    private function seedTariff(
        string $provider,
        string $model,
        string $input,
        string $output,
        bool $isDefault,
    ): void {
        $model = trim($model);
        if ($model === '') {
            return;
        }

        AiCabinetAnalyzerCreditTariff::query()->firstOrCreate(
            [
                'provider' => $provider,
                'model' => $model,
            ],
            [
                'input_credits_per_1k' => $input,
                'output_credits_per_1k' => $output,
                'coefficient' => '1.0000',
                'is_default' => $isDefault,
                'is_active' => true,
            ],
        );
    }
}
