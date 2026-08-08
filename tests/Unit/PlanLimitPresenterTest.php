<?php

namespace Tests\Unit;

use App\Support\PlanLimitPresenter;
use PHPUnit\Framework\TestCase;

class PlanLimitPresenterTest extends TestCase
{
    public function test_collapses_legacy_wb_cabinet_keys_into_wb_cabinets(): void
    {
        $entries = PlanLimitPresenter::displayEntries([
            'feedbacks_clients' => 3,
            'price_calc_clients' => 1,
            'oz_cabinets' => 2,
            'repricer_nmid' => 50,
        ], [
            'ai_text_query' => 100,
        ]);

        $keys = array_column($entries, 'key');

        $this->assertContains('wb_cabinets', $keys);
        $this->assertNotContains('feedbacks_clients', $keys);
        $this->assertNotContains('price_calc_clients', $keys);
        $this->assertContains('oz_cabinets', $keys);
        $this->assertContains('repricer_nmid', $keys);
        $this->assertContains('ai_text_query', $keys);

        $wb = collect($entries)->firstWhere('key', 'wb_cabinets');
        $this->assertSame(3, $wb['value']);
        $this->assertSame('Единый кабинет Wildberries', $wb['label']);
        $this->assertSame('Кабинет на все услуги для маркетплейса Wildberries', $wb['hint']);
    }

    public function test_prefers_explicit_wb_cabinets_over_legacy(): void
    {
        $entries = PlanLimitPresenter::displayEntries([
            'wb_cabinets' => 5,
            'feedbacks_clients' => 99,
            'price_calc_clients' => 99,
        ]);

        $wb = collect($entries)->firstWhere('key', 'wb_cabinets');
        $this->assertSame(5, $wb['value']);
        $this->assertCount(1, $entries);
    }

    public function test_normalize_remaining_map_collapses_legacy_keys(): void
    {
        $map = PlanLimitPresenter::normalizeRemainingMap([
            'feedbacks_clients' => 2,
            'price_calc_clients' => 1,
            'ai_text_query' => 10,
        ]);

        $this->assertSame(2, $map['wb_cabinets']);
        $this->assertSame(10, $map['ai_text_query']);
        $this->assertArrayNotHasKey('feedbacks_clients', $map);
        $this->assertArrayNotHasKey('price_calc_clients', $map);
    }

    public function test_drops_legacy_oz_cabinet_keys(): void
    {
        $entries = PlanLimitPresenter::displayEntries([
            'oz_price_calc_clients' => 4,
            'oz_feedbacks_clients' => 1,
            'oz_cabinets' => 3,
        ]);

        $keys = array_column($entries, 'key');

        $this->assertContains('oz_cabinets', $keys);
        $this->assertNotContains('oz_price_calc_clients', $keys);
        $this->assertNotContains('oz_feedbacks_clients', $keys);

        $oz = collect($entries)->firstWhere('key', 'oz_cabinets');
        $this->assertSame(3, $oz['value']);
        $this->assertSame('Единый кабинет Ozon', $oz['label']);
        $this->assertSame('Кабинет на все услуги для маркетплейса Ozon', $oz['hint']);
    }

    public function test_drops_legacy_oz_keys_without_oz_cabinets(): void
    {
        $entries = PlanLimitPresenter::displayEntries([
            'oz_price_calc_clients' => 4,
            'oz_feedbacks_clients' => 1,
        ]);

        $keys = array_column($entries, 'key');

        $this->assertNotContains('oz_cabinets', $keys);
        $this->assertNotContains('oz_price_calc_clients', $keys);
        $this->assertNotContains('oz_feedbacks_clients', $keys);
        $this->assertSame([], $entries);
    }
}
