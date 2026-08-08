<?php

namespace Tests\Unit;

use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerTemplate;
use App\Services\Gemini\GeminiApiClient;
use App\Services\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerAiAnalysisService;
use Mockery;
use Tests\TestCase;

class AiCabinetAnalyzerDataSourcesFilterTest extends TestCase
{
    private AiCabinetAnalyzerAiAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $gemini = Mockery::mock(GeminiApiClient::class);
        $this->service = new AiCabinetAnalyzerAiAnalysisService($gemini);
    }

    public function test_filter_keeps_only_ads_fields(): void
    {
        $filtered = $this->service->filterDatasetBySources($this->sampleDataset(), ['ads']);

        $this->assertNotEmpty($filtered['campaigns']);
        $this->assertSame([], $filtered['feedbacks']);
        $this->assertArrayHasKey('clicks', $filtered['items'][0]);
        $this->assertArrayNotHasKey('funnel', $filtered['items'][0]);
        $this->assertArrayNotHasKey('reviews', $filtered['items'][0]);
        $this->assertArrayNotHasKey('ads_vs_funnel', $filtered['items'][0]);
        $this->assertArrayNotHasKey('funnel_nmids_total', $filtered['meta']['totals']);
        $this->assertArrayHasKey('campaigns_count', $filtered['meta']['totals']);
    }

    public function test_filter_keeps_only_funnel_fields(): void
    {
        $filtered = $this->service->filterDatasetBySources($this->sampleDataset(), ['funnel']);

        $this->assertSame([], $filtered['campaigns']);
        $this->assertSame([], $filtered['feedbacks']);
        $this->assertArrayHasKey('funnel', $filtered['items'][0]);
        $this->assertArrayNotHasKey('clicks', $filtered['items'][0]);
        $this->assertArrayNotHasKey('reviews', $filtered['items'][0]);
        $this->assertArrayNotHasKey('ads_vs_funnel', $filtered['items'][0]);
        $this->assertArrayHasKey('funnel_nmids_total', $filtered['meta']['totals']);
        $this->assertArrayNotHasKey('campaigns_count', $filtered['meta']['totals']);
    }

    public function test_filter_keeps_only_reviews_fields(): void
    {
        $filtered = $this->service->filterDatasetBySources($this->sampleDataset(), ['reviews']);

        $this->assertSame([], $filtered['campaigns']);
        $this->assertNotEmpty($filtered['feedbacks']);
        $this->assertArrayHasKey('reviews', $filtered['items'][0]);
        $this->assertArrayNotHasKey('funnel', $filtered['items'][0]);
        $this->assertArrayNotHasKey('clicks', $filtered['items'][0]);
        $this->assertArrayHasKey('reviews_nmids_with_data', $filtered['meta']['totals']);
    }

    public function test_ads_vs_funnel_only_when_both_sources_enabled(): void
    {
        $both = $this->service->filterDatasetBySources($this->sampleDataset(), ['ads', 'funnel']);
        $this->assertArrayHasKey('ads_vs_funnel', $both['items'][0]);

        $adsOnly = $this->service->filterDatasetBySources($this->sampleDataset(), ['ads']);
        $this->assertArrayNotHasKey('ads_vs_funnel', $adsOnly['items'][0]);
    }

    public function test_template_resolved_data_sources_defaults_to_all(): void
    {
        $template = new AiCabinetAnalyzerTemplate([
            'data_sources' => null,
        ]);

        $this->assertSame(
            AiCabinetAnalyzerTemplate::DATA_SOURCES,
            $template->resolvedDataSources()
        );

        $template->data_sources = ['ads', 'invalid', 'funnel', 'ads'];
        $this->assertSame(['ads', 'funnel'], $template->resolvedDataSources());
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleDataset(): array
    {
        return [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'period' => ['start' => '2026-01-01', 'end' => '2026-01-31'],
                'totals' => [
                    'campaigns_count' => 2,
                    'campaigns_with_nmids' => 1,
                    'unique_nmids_count' => 1,
                    'funnel_nmids_total' => 10,
                    'funnel_nmids_with_data' => 5,
                    'reviews_nmids_with_data' => 3,
                    'feedbacks_in_period_count' => 4,
                    'feedbacks_fetched_count' => 8,
                ],
            ],
            'campaigns' => [
                [
                    'advert_id' => 100,
                    'nmids' => [111],
                    'stats' => ['clicks' => 10, 'views' => 100, 'spend' => 50.5, 'orders' => 2],
                ],
            ],
            'items' => [
                [
                    'nmid' => 111,
                    'advert_ids' => [100],
                    'campaigns_count' => 1,
                    'clicks' => 10,
                    'views' => 100,
                    'spend' => 50.5,
                    'orders' => 2,
                    'ctr' => 10.0,
                    'cpc' => 5.05,
                    'cr' => 20.0,
                    'funnel' => [
                        'open_count' => 200,
                        'cart_count' => 20,
                        'order_count' => 5,
                    ],
                    'ads_vs_funnel' => [
                        'orders_gap' => -3,
                        'orders_ratio_ads_to_funnel' => 0.4,
                    ],
                    'reviews' => [
                        'pros' => ['качество'],
                        'cons' => ['цена'],
                        'average_rating' => 4.5,
                    ],
                ],
            ],
            'feedbacks' => [
                [
                    'id' => 'fb1',
                    'text' => 'ok',
                    'productDetails' => ['nmId' => 111],
                ],
            ],
        ];
    }
}
