<?php

namespace Tests\Feature\Web\Subscriber;

use Tests\Feature\Web\Auth\WebAuthTestCase;

class PlatformApiRemovalTest extends WebAuthTestCase
{
    public function test_legacy_platform_api_routes_are_removed(): void
    {
        $this->getJson('/api/client/me')->assertNotFound();

        $this->getJson('/api/subscriber/blog/posts')->assertNotFound();

        $this->postJson('/api/subscriber/user/profile', ['name' => 'Test'])->assertNotFound();

        $this->getJson('/api/subscriber/user/subscriptions')->assertNotFound();

        $this->getJson('/api/payments/history')->assertNotFound();

        $this->postJson('/api/payments/yoo/create', ['amount' => 100])->assertNotFound();
    }
}