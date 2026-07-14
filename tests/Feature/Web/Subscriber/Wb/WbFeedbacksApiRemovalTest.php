<?php

namespace Tests\Feature\Web\Subscriber\Wb;

use Tests\Feature\Web\Auth\WebAuthTestCase;

class WbFeedbacksApiRemovalTest extends WebAuthTestCase
{
    public function test_legacy_wb_feedbacks_api_routes_are_removed(): void
    {
        $this->postJson('/api/subscriber/wb/feedbacks/list', ['client_id' => 1])
            ->assertNotFound();

        $this->postJson('/api/subscriber/wb/feedbacks/send', ['client_id' => 1])
            ->assertNotFound();

        $this->getJson('/api/subscriber/wb/feedbacks/client/bot-status?client_id=1')
            ->assertNotFound();

        $this->getJson('/api/subscriber/wb/feedbacks/client/ai/data?client_id=1')
            ->assertNotFound();

        $this->postJson('/api/subscriber/wb/feedbacks/templates/all', ['client_id' => 1])
            ->assertNotFound();

        $this->getJson('/api/subscriber/wb/feedbacks/widget/answered?cabinet_id=1')
            ->assertNotFound();

        $this->getJson('/api/subscriber/wb/feedbacks/stats/product?cabinet_id=1&product_id=1')
            ->assertNotFound();
    }
}