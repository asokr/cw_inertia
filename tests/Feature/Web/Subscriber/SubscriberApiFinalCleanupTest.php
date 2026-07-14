<?php

namespace Tests\Feature\Web\Subscriber;

use Tests\Feature\Web\Auth\WebAuthTestCase;

class SubscriberApiFinalCleanupTest extends WebAuthTestCase
{
    public function test_legacy_subscriber_api_routes_are_removed(): void
    {
        $this->getJson('/api/subscriber/wb/feedbacks')->assertNotFound();
        $this->getJson('/api/subscriber/oz/feedbacks')->assertNotFound();
        $this->getJson('/api/subscriber/ai/image/generations')->assertNotFound();
        $this->getJson('/api/subscriber/user/profile')->assertNotFound();
        $this->postJson('/api/check-coupon', ['code' => 'TEST'])->assertNotFound();
    }
}