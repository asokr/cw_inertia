<?php

namespace Tests\Unit;

use App\Services\Oz\AiCabinetAnalyzer\OzAiCabinetAnalyzerAdsCollector;
use App\Services\Ozon\OzonPerformanceApiService;
use PHPUnit\Framework\TestCase;

class OzAiCabinetAnalyzerAdsCollectorTest extends TestCase
{
    public function test_skipped_without_credentials(): void
    {
        $collector = new OzAiCabinetAnalyzerAdsCollector(new OzonPerformanceApiService());
        $result = $collector->collect(null, null, '2026-08-01', '2026-08-31');

        $this->assertSame('skipped_no_credentials', $result['status']);
        $this->assertSame([], $result['campaigns']);
        $this->assertSame([], $result['by_sku']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_cpc_async_report_fills_sku_metrics(): void
    {
        $api = $this->createMock(OzonPerformanceApiService::class);
        $api->expects($this->never())->method('getCampaignProductStatistics');
        $api->expects($this->never())->method('getCampaignProductStatisticsJson');
        $api->method('getAccessToken')->willReturn($this->ok(['access_token' => 'tok']));
        $api->method('listCampaigns')->willReturn($this->ok([
            'list' => [[
                'id' => 11037395,
                'title' => 'CPC',
                'state' => 'CAMPAIGN_STATE_RUNNING',
                'advObjectType' => 'SKU',
                'paymentType' => 'CPC',
            ]],
        ]));
        $api->method('requestStatisticsJson')->willReturn($this->ok(['UUID' => 'uuid-cpc']));
        $api->method('generateSearchPromoProductsReportJson')->willReturn($this->ok(['UUID' => 'uuid-cpo']));
        $api->method('generateAllSkuPromoProductsReportJson')->willReturn($this->ok(['UUID' => 'uuid-promo']));
        $api->method('getStatisticsStatus')->willReturn($this->ok(['state' => 'OK']));
        $api->method('downloadStatisticsReport')->willReturnCallback(function (string $token, string $uuid): array {
            if ($uuid === 'uuid-cpc') {
                return $this->ok([
                    'report' => [
                        'rows' => [[
                            'sku' => '9001',
                            'campaignId' => '11037395',
                            'views' => '100',
                            'clicks' => '10',
                            'expense' => '250.50',
                            'orders' => '3',
                            'sales' => '1500',
                            'toCart' => '7',
                        ]],
                    ],
                ]);
            }

            return $this->ok(['rows' => []]);
        });

        $collector = new OzAiCabinetAnalyzerAdsCollector($api);
        $result = $collector->collect('perf-id', 'perf-secret', '2026-08-01', '2026-08-31');

        $this->assertSame('collected', $result['status']);
        $this->assertCount(1, $result['campaigns']);
        $this->assertSame(11037395, $result['campaigns'][0]['id']);
        $this->assertArrayHasKey(9001, $result['by_sku']);
        $this->assertSame(100, $result['by_sku'][9001]['views']);
        $this->assertSame(10, $result['by_sku'][9001]['clicks']);
        $this->assertSame(250.5, $result['by_sku'][9001]['spend']);
        $this->assertSame(3, $result['by_sku'][9001]['orders']);
        $this->assertSame(1500.0, $result['by_sku'][9001]['orders_money']);
        $this->assertSame(7, $result['by_sku'][9001]['to_cart']);
        $this->assertSame([11037395], $result['by_sku'][9001]['campaign_ids']);
    }

    public function test_cpo_generate_report_fills_sku_metrics(): void
    {
        $api = $this->createMock(OzonPerformanceApiService::class);
        $api->expects($this->never())->method('getCampaignProductStatistics');
        $api->expects($this->never())->method('requestStatisticsJson');
        $api->expects($this->never())->method('generateAllSkuPromoProductsReportJson');
        $api->method('getAccessToken')->willReturn($this->ok(['access_token' => 'tok']));
        $api->method('listCampaigns')->willReturn($this->ok([
            'list' => [[
                'id' => 55,
                'title' => 'CPO',
                'state' => 'CAMPAIGN_STATE_RUNNING',
                'advObjectType' => 'SEARCH_PROMO',
                'paymentType' => 'CPO',
            ]],
        ]));
        $api->method('generateSearchPromoProductsReportJson')->willReturn($this->ok(['UUID' => 'uuid-cpo']));
        $api->method('getStatisticsStatus')->willReturn($this->ok(['state' => 'OK']));
        $api->method('downloadStatisticsReport')->willReturn($this->ok([
            'rows' => [[
                'SKU' => 8002,
                'Views' => 40,
                'Clicks' => 4,
                'MoneySpent' => '80.00',
                'Orders' => 2,
                'OrdersMoney' => '900',
                'ToCart' => 3,
            ]],
        ]));

        $collector = new OzAiCabinetAnalyzerAdsCollector($api);
        $result = $collector->collect('perf-id', 'perf-secret', '2026-08-01', '2026-08-07');

        $this->assertSame('collected', $result['status']);
        $this->assertSame('SEARCH_PROMO', $result['campaigns'][0]['adv_object_type']);
        $this->assertArrayHasKey(8002, $result['by_sku']);
        $this->assertSame(40, $result['by_sku'][8002]['views']);
        $this->assertSame(80.0, $result['by_sku'][8002]['spend']);
        $this->assertSame(2, $result['by_sku'][8002]['orders']);
    }

    public function test_empty_sku_rows_from_campaign_level_payload_do_not_fill_by_sku(): void
    {
        $api = $this->createMock(OzonPerformanceApiService::class);
        $api->method('getAccessToken')->willReturn($this->ok(['access_token' => 'tok']));
        $api->method('listCampaigns')->willReturn($this->ok([
            'list' => [[
                'id' => 1,
                'title' => 'CPC',
                'advObjectType' => 'SKU',
            ]],
        ]));
        $api->method('requestStatisticsJson')->willReturn($this->ok(['UUID' => 'uuid-cpc']));
        $api->method('generateSearchPromoProductsReportJson')->willReturn($this->ok(['UUID' => 'uuid-cpo']));
        $api->method('generateAllSkuPromoProductsReportJson')->willReturn($this->ok(['UUID' => 'uuid-promo']));
        $api->method('getStatisticsStatus')->willReturn($this->ok(['state' => 'OK']));
        $api->method('downloadStatisticsReport')->willReturn($this->ok([
            'rows' => [[
                'campaignId' => '1',
                'title' => 'CPC',
                'views' => '500',
                'clicks' => '20',
                'expense' => '100',
            ]],
        ]));

        $collector = new OzAiCabinetAnalyzerAdsCollector($api);
        $result = $collector->collect('perf-id', 'perf-secret', '2026-08-01', '2026-08-07');

        $this->assertSame('collected', $result['status']);
        $this->assertSame([], $result['by_sku']);
        $types = array_column($result['warnings'], 'type');
        $this->assertContains('advertising_stats_empty', $types);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{success: bool, status: int, data: array<string, mixed>}
     */
    private function ok(array $data): array
    {
        return [
            'success' => true,
            'status' => 200,
            'data' => $data,
        ];
    }
}
