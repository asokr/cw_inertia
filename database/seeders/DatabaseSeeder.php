<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(AiCabinetAnalyzerTemplatesSeeder::class);

        // Одноразовый перенос данных ценообразования WB из V2 в V3.
        // Запускать адресно: php artisan db:seed --class=Database\\Seeders\\WbPriceCalculationV2ToV3Seeder
        // $this->call(WbPriceCalculationV2ToV3Seeder::class);
    }
}
