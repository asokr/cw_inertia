<?php

namespace Database\Seeders;

use App\Models\Subscribers\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerTemplate;
use Illuminate\Database\Seeder;

class OzAiCabinetAnalyzerTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Обзор каталога',
                'description' => 'Анализирует состав каталога Ozon: полноту карточек, статусы, архив, цены и изображения.',
                'system_prompt' => 'Ты senior-аналитик каталога маркетплейса Ozon. По данным snapshot каталога товаров дай прикладной анализ: полнота карточек, проблемные статусы, архив, дубли артикулов, отсутствие изображений/цен. Предложи конкретные действия. Отвечай на русском языке.',
                'sort_order' => 10,
                'is_active' => true,
                'response_format' => 'json',
                'data_sources' => ['products'],
            ],
            [
                'name' => 'Продажи и ассортимент',
                'description' => 'Связывает каталог с free-аналитикой (выручка/заказы), остатками и поисковым спросом.',
                'system_prompt' => 'Ты senior-аналитик Ozon. По snapshot (каталог + analytics.revenue/ordered_units + search + stocks/turnover) выдели топ и аутсайдеров по заказам/выручке, риски остатков, слабый поисковый спрос. Учти: без Premium нет показов/корзин/конверсий воронки — не выдумывай эти метрики. Отвечай на русском.',
                'sort_order' => 15,
                'is_active' => true,
                'response_format' => 'json',
                'data_sources' => ['products', 'analytics', 'search', 'stocks'],
            ],
            [
                'name' => 'Реклама vs продажи',
                'description' => 'Сравнивает рекламные метрики Performance API с free-продажами по SKU.',
                'system_prompt' => 'Ты performance-маркетолог Ozon. Сравни advertising (spend, clicks, orders) с analytics (revenue, ordered_units) и ads_vs_analytics. Найди перерасход, товары без рекламы с продажами и наоборот. Отвечай на русском.',
                'sort_order' => 18,
                'is_active' => true,
                'response_format' => 'json',
                'data_sources' => ['products', 'analytics', 'advertising'],
            ],
            [
                'name' => 'Markdown-отчёт по каталогу',
                'description' => 'Формирует читаемый Markdown-отчёт по каталогу товаров Ozon.',
                'system_prompt' => 'Ты senior-аналитик маркетплейса Ozon. Сформируй структурированный Markdown-отчёт по каталогу: резюме, риски, рекомендации, метрики. Отвечай на русском языке.',
                'sort_order' => 20,
                'is_active' => true,
                'response_format' => 'markdown',
                'data_sources' => ['products'],
            ],
        ];

        foreach ($templates as $template) {
            OzAiCabinetAnalyzerTemplate::updateOrCreate(
                ['name' => $template['name']],
                $template,
            );
        }
    }
}
