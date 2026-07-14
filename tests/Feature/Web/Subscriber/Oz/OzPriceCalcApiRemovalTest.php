<?php

namespace Tests\Feature\Web\Subscriber\Oz;

use Tests\Feature\Web\Auth\WebAuthTestCase;

class OzPriceCalcApiRemovalTest extends WebAuthTestCase
{
    public function test_legacy_oz_price_calc_api_routes_are_removed(): void
    {
        $this->getJson('/api/subscriber/oz/price-calc/cabinets')
            ->assertNotFound();

        $this->getJson('/api/subscriber/oz/price-calc/cabinets/1/fbo')
            ->assertNotFound();

        $this->postJson('/api/subscriber/oz/price-calc/cabinets/1/sync')
            ->assertNotFound();

        $this->getJson('/api/subscriber/oz/price-calc/cabinets/1/fbs')
            ->assertNotFound();

        $this->postJson('/api/subscriber/oz/price-calc/cabinets/1/fbs/calculate')
            ->assertNotFound();
    }
}