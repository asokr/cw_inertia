<?php

namespace Database\Seeders;

use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerTemplate;
use Illuminate\Database\Seeder;

class AiCabinetAnalyzerTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Анализ рекламы',
                'description' => 'Анализирует эффективность рекламных кампаний и расход бюджета.',
                'system_prompt' => 'Ты senior-аналитик рекламы маркетплейса WildBerries. Дай прикладной анализ расходов, эффективности, узких мест и действий. Отвечай на русском языке.',
                'sort_order' => 10,
                'is_active' => true,
                'response_format' => 'json',
            ],
            [
                'name' => 'Анализ прибыли',
                'description' => 'Оценивает влияние рекламы и воронки на прибыльность номенклатуры.',
                'system_prompt' => 'Ты senior-аналитик прибыльности маркетплейса WildBerries. Выдели убыточные и рентабельные зоны, поясни причины и предложи меры. Отвечай на русском языке.',
                'sort_order' => 20,
                'is_active' => true,
                'response_format' => 'json',
            ],
            [
                'name' => 'Точки роста',
                'description' => 'Находит направления роста по товарам и кампаниям.',
                'system_prompt' => 'Ты эксперт по росту продаж маркетплейса WildBerries. Найди точки роста по данным рекламы и воронки, укажи ожидаемый эффект и приоритет внедления. Отвечай на русском языке.',
                'sort_order' => 30,
                'is_active' => true,
                'response_format' => 'json',
            ],
            [
                'name' => 'Ошибки воронки',
                'description' => 'Ищет проблемные места в воронке и потери конверсии.',
                'system_prompt' => 'Ты эксперт по воронке продаж маркетплейса WildBerries. Выяви аномалии и ошибки на этапах воронки, объясни их влияние и предложи корректировки. Отвечай на русском языке.',
                'sort_order' => 40,
                'is_active' => true,
                'response_format' => 'json',
            ],
            [
                'name' => 'План действий',
                'description' => 'Формирует пошаговый план улучшений на основе отчёта.',
                'system_prompt' => 'Ты стратег маркетплейса WildBerries. Сформируй конкретный план действий по приоритетам: быстрые победы, среднесрочные задачи, контрольные метрики. Отвечай на русском языке.',
                'sort_order' => 50,
                'is_active' => true,
                'response_format' => 'json',
            ],
        ];

        foreach ($templates as $template) {
            AiCabinetAnalyzerTemplate::updateOrCreate(
                ['name' => $template['name']],
                $template,
            );
        }
    }
}
