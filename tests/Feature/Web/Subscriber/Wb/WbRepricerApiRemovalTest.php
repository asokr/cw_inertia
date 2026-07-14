<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbRepricerApiRemovalTest extends WebAuthTestCase
{
    public function test_legacy_wb_repricer_api_routes_are_removed(): void
    {
        $this->getJson('/api/subscriber/wb/repricer/cabinets')
            ->assertNotFound();

        $this->postJson('/api/subscriber/wb/repricer/cabinets/logs', ['cabinet_id' => 1])
            ->assertNotFound();

        $this->getJson('/api/subscriber/wb/repricer/stocks/1')
            ->assertNotFound();

        $this->postJson('/api/subscriber/wb/repricer/stocks/sizes/', ['cabinet_id' => 1, 'nm_id' => 1])
            ->assertNotFound();

        $this->getJson('/api/subscriber/wb/repricer/1')
            ->assertNotFound();

        $this->getJson('/api/subscriber/wb/repricer/competitors')
            ->assertNotFound();

        $this->getJson('/api/subscriber/wb/repricer/competitors/search?query=test')
            ->assertNotFound();
    }
}