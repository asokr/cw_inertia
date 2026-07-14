<?php

namespace Tests\Feature\Web\Webhook;

use Tests\TestCase;

class WebhookRoutesTest extends TestCase
{
    public function test_yookassa_webhook_route_is_registered(): void
    {
        $this->post('/api/payments/yoo/callback', [])
            ->assertNoContent();
    }

    public function test_wb_search_webhook_route_is_removed(): void
    {
        $this->postJson('/api/services/wb-search/webhook', [])
            ->assertNotFound();
    }
}