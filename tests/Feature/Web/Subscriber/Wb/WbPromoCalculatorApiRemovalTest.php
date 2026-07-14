<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbPromoCalculatorApiRemovalTest extends WebAuthTestCase
{
    public function test_legacy_wb_promo_calculator_api_routes_are_removed(): void
    {
        $this->postJson('/api/subscriber/wb/promo-calculator/upload')
            ->assertNotFound();

        $this->postJson('/api/subscriber/wb/promo-calculator/calc', ['cabinet_id' => 1])
            ->assertNotFound();

        $this->postJson('/api/subscriber/wb/promo-calculator/xlsx')
            ->assertNotFound();

        $this->postJson('/api/subscriber/wb/promo-calculator/repricer', ['cabinet_id' => 1])
            ->assertNotFound();
    }
}