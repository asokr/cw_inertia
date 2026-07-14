<?php

namespace Tests\Feature\Web\Subscriber\Oz;

use Tests\Feature\Web\Auth\WebAuthTestCase;

class OzFeedbacksApiRemovalTest extends WebAuthTestCase
{
    public function test_legacy_oz_feedbacks_api_routes_are_removed(): void
    {
        $this->postJson('/api/subscriber/oz/feedbacks/list', ['cabinet_id' => 1])
            ->assertNotFound();

        $this->postJson('/api/subscriber/oz/feedbacks/send', ['cabinet_id' => 1])
            ->assertNotFound();

        $this->postJson('/api/subscriber/oz/feedbacks/count', ['cabinet_id' => 1])
            ->assertNotFound();

        $this->getJson('/api/subscriber/oz/feedbacks/cabinets/ai/data/1')
            ->assertNotFound();

        $this->getJson('/api/subscriber/oz/feedbacks/cabinets/bot-status?cabinet_id=1')
            ->assertNotFound();

        $this->getJson('/api/subscriber/oz/feedbacks/cabinets')
            ->assertNotFound();
    }
}