<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbProfitabilityApiRemovalTest extends WebAuthTestCase
{
    public function test_legacy_wb_profitability_api_routes_are_removed(): void
    {
        $this->getJson('/api/subscriber/wb/profitability/cabinets')
            ->assertNotFound();

        $this->getJson('/api/subscriber/wb/profitability/1')
            ->assertNotFound();

        $this->getJson('/api/subscriber/wb/profitability/status/1')
            ->assertNotFound();

        $this->postJson('/api/subscriber/wb/profitability', ['cabinet_id' => 1])
            ->assertNotFound();
    }
}