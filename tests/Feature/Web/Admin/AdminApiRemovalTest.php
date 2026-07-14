<?php

namespace Tests\Feature\Web\Admin;

use Tests\Feature\Web\Auth\WebAuthTestCase;

class AdminApiRemovalTest extends WebAuthTestCase
{
    public function test_legacy_admin_api_routes_are_removed(): void
    {
        $this->getJson('/api/admin/subscribers')->assertNotFound();
        $this->postJson('/api/admin/subscribers/list')->assertNotFound();
        $this->getJson('/api/admin/coupons')->assertNotFound();
        $this->getJson('/api/admin/sent-emails')->assertNotFound();

        $this->postJson('/api/admin/widgets/last-registered')->assertNotFound();
        $this->postJson('/api/admin/widgets/last-subscriptions')->assertNotFound();
        $this->postJson('/api/admin/subscribers/payments')->assertNotFound();

        $this->getJson('/api/admin/blog/posts')->assertNotFound();
        $this->postJson('/api/admin/blog/upload-image')->assertNotFound();

        $this->getJson('/api/admin/services/feedbacks/cabinets')->assertNotFound();
        $this->getJson('/api/admin/services/ai/marketplace-logs')->assertNotFound();
        $this->getJson('/api/admin/wb/api-usage-stats')->assertNotFound();

        $this->postJson('/api/admin/get-roles')->assertNotFound();
        $this->postJson('/api/admin/users')->assertNotFound();

        $this->getJson('/api/fullfilment')->assertNotFound();
        $this->getJson('/api/admin/fullfilment')->assertNotFound();

        $this->getJson('/api/dashboard/user/profile/')->assertNotFound();
        $this->postJson('/api/dashboard/user/profile/')->assertNotFound();
    }
}