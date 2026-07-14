<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbPriceCalcApiRemovalTest extends WebAuthTestCase
{
    public function test_legacy_wb_price_calc_api_routes_are_removed(): void
    {
        $this->getJson('/api/subscriber/wb/price-calculation/cabinets')
            ->assertNotFound();

        $this->getJson('/api/subscriber/wb/price-calculation-v3/cards/1')
            ->assertNotFound();

        $this->postJson('/api/subscriber/wb/price-calculation-v3/cards/sync', ['cabinet_id' => 1])
            ->assertNotFound();

        $this->getJson('/api/subscriber/wb/price-calculation-v3/settings/1')
            ->assertNotFound();

        $this->postJson('/api/subscriber/wb/price-calculation-v3/calculate', ['cabinet_id' => 1])
            ->assertNotFound();
    }
}