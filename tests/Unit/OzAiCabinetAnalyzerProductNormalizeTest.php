<?php

namespace Tests\Unit;

use App\Services\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerAdsCollector;
use App\Services\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerContentRatingCollector;
use App\Services\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerAiAnalysisService;
use App\Services\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerAnalyticsCollector;
use App\Services\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerProductQueriesCollector;
use App\Services\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerProductsCollector;
use App\Services\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerStocksCollector;
use App\Services\Ozon\OzonApiService;
use App\Services\Ozon\OzonPerformanceApiService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class OzAiCabinetAnalyzerProductNormalizeTest extends TestCase
{
    public function test_normalize_product_maps_core_fields_and_skus(): void
    {
        $collector = new OzAiCabinetAnalyzerProductsCollector(new OzonApiService());
        $method = (new ReflectionClass($collector))->getMethod('normalizeProduct');
        $method->setAccessible(true);

        $listItem = [
            'product_id' => 101,
            'offer_id' => 'ART-101',
            'archived' => false,
            'has_fbo_stocks' => true,
            'has_fbs_stocks' => false,
            'is_discounted' => false,
        ];

        $detail = [
            'id' => 101,
            'offer_id' => 'ART-101',
            'sku' => 9001,
            'fbo_sku' => 9001,
            'fbs_sku' => 0,
            'name' => 'Товар тест',
            'barcodes' => ['460123'],
            'description_category_id' => 55,
            'type_id' => 77,
            'images' => ['https://cdn.example/a.jpg'],
            'primary_image' => ['https://cdn.example/main.jpg'],
            'visible' => true,
            'is_archived' => false,
            'is_autoarchived' => false,
            'price' => '1990.00',
            'old_price' => '2500.00',
            'currency_code' => 'RUB',
            'price_indexes' => [
                'color_index' => 'GREEN',
                'ozon_index_data' => [
                    'minimal_price' => '1890.00',
                    'minimal_price_currency' => 'RUB',
                    'price_index_value' => 0.95,
                ],
            ],
            'commissions' => [
                ['sale_schema' => 'fbo', 'percent' => 15, 'value' => 200],
            ],
            'promotions' => [
                ['type' => 'DISCOUNT', 'is_enabled' => true],
            ],
            'errors' => [
                ['code' => 'INVALID_PRICE', 'field' => 'price', 'texts' => ['message' => 'Исправьте цену']],
            ],
            'stocks' => ['has_stock' => true],
            'availabilities' => [
                ['reasons' => [['human_text' => ['text' => 'Товар доступен на складе']]]],
            ],
            'sources' => [
                ['sku' => 9001, 'source' => 'sds', 'shipment_type' => 'general'],
            ],
            'statuses' => [
                'status' => 'price_sent',
                'status_name' => 'Продается',
            ],
        ];

        $product = $method->invoke($collector, $listItem, $detail, 'Бренд X');

        $this->assertSame(101, $product['product_id']);
        $this->assertSame('ART-101', $product['offer_id']);
        $this->assertSame(9001, $product['sku']);
        $this->assertSame(9001, $product['skus']['fbo']);
        $this->assertContains(9001, $product['skus']['all']);
        $this->assertSame('Товар тест', $product['name']);
        $this->assertSame('Бренд X', $product['brand']);
        $this->assertSame('https://cdn.example/main.jpg', $product['primary_image']);
        $this->assertSame(['460123'], $product['barcodes']);
        $this->assertTrue($product['visible']);
        $this->assertSame('GREEN', $product['price_indexes']['color_index']);
        $this->assertSame(15.0, $product['commissions'][0]['percent']);
        $this->assertTrue($product['promotions'][0]['is_enabled']);
        $this->assertSame('INVALID_PRICE', $product['errors'][0]['code']);
        $this->assertTrue($product['availability']['has_stock']);
        $this->assertContains('Товар доступен на складе', $product['availability']['reasons']);
        $this->assertIsArray($product['raw']);
        $this->assertSame(101, $product['raw']['id']);
    }

    public function test_empty_analytics_block_structure(): void
    {
        $block = OzAiCabinetAnalyzerAnalyticsCollector::emptyAnalyticsBlock('2026-01-01', '2026-01-31');
        $this->assertSame(0.0, $block['revenue']);
        $this->assertSame(0.0, $block['ordered_units']);
        $this->assertSame('2026-01-01', $block['period']['begin_date']);
    }

    public function test_content_rating_empty_skus_returns_empty(): void
    {
        $collector = new OzAiCabinetAnalyzerContentRatingCollector(new OzonApiService());
        $result = $collector->collect('key', '1', []);

        $this->assertSame([], $result['by_sku']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_ads_skipped_without_credentials(): void
    {
        $collector = new OzAiCabinetAnalyzerAdsCollector(new OzonPerformanceApiService());
        $result = $collector->collect(null, null, '2026-01-01', '2026-01-31');

        $this->assertSame('skipped_no_credentials', $result['status']);
        $this->assertSame([], $result['campaigns']);
        $this->assertSame([], $result['by_sku']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_filter_dataset_by_sources_strips_blocks(): void
    {
        $gemini = $this->createMock(\App\Services\Gemini\GeminiApiClient::class);
        $service = new OzAiCabinetAnalyzerAiAnalysisService($gemini);

        $dataset = [
            'meta' => [
                'products_count' => 1,
                'seller_rating' => ['premium' => false, 'groups' => []],
                'actions' => [['id' => 7]],
                'actions_count' => 1,
            ],
            'campaigns' => [['id' => 1]],
            'products' => [
                [
                    'product_id' => 1,
                    'name' => 'A',
                    'analytics' => ['revenue' => 10, 'ordered_units' => 2],
                    'search' => ['unique_search_users' => 5],
                    'stocks' => ['free_to_sell' => 3],
                    'turnover' => ['idc' => 1],
                    'advertising' => ['spend' => 1.5, 'orders' => 1],
                    'ads_vs_analytics' => ['orders_gap' => -1],
                    'content_rating' => ['rating' => 42.5, 'groups' => []],
                    'promos' => [['action_id' => 7]],
                    'liquidity' => ['turnover_grade' => 'DEFICIT'],
                ],
            ],
        ];

        $filtered = $service->filterDatasetBySources($dataset, ['products', 'analytics']);

        $this->assertArrayHasKey('analytics', $filtered['products'][0]);
        $this->assertArrayNotHasKey('advertising', $filtered['products'][0]);
        $this->assertArrayNotHasKey('search', $filtered['products'][0]);
        $this->assertArrayNotHasKey('ads_vs_analytics', $filtered['products'][0]);
        $this->assertArrayNotHasKey('content_rating', $filtered['products'][0]);
        $this->assertArrayNotHasKey('promos', $filtered['products'][0]);
        $this->assertArrayNotHasKey('campaigns', $filtered);
        $this->assertArrayNotHasKey('seller_rating', $filtered['meta']);
        $this->assertArrayNotHasKey('actions', $filtered['meta']);

        $both = $service->filterDatasetBySources($dataset, ['analytics', 'advertising']);
        $this->assertArrayHasKey('ads_vs_analytics', $both['products'][0]);
        $this->assertArrayHasKey('campaigns', $both);

        $quality = $service->filterDatasetBySources($dataset, ['products', 'content', 'seller_rating', 'promos']);
        $this->assertArrayHasKey('content_rating', $quality['products'][0]);
        $this->assertArrayHasKey('promos', $quality['products'][0]);
        $this->assertArrayHasKey('seller_rating', $quality['meta']);
        $this->assertSame(1, $quality['meta']['actions_count']);
    }
}
