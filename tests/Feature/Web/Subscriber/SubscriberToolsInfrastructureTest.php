<?php

namespace Tests\Feature\Web\Subscriber;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class SubscriberToolsInfrastructureTest extends WebAuthTestCase
{
    public function test_subscriber_tools_route_file_exists(): void
    {
        $this->assertFileExists(base_path('routes/subscriber-tools.php'));
    }

    public function test_web_routes_include_subscriber_tools_file(): void
    {
        $webRoutes = file_get_contents(base_path('routes/web.php'));

        $this->assertIsString($webRoutes);
        $this->assertStringContainsString('subscriber-tools.php', $webRoutes);
    }

    public function test_guest_cannot_access_subscriber_panel(): void
    {
        $this->get('/panel')->assertUnauthorized();
    }

    public function test_user_without_subscriber_role_cannot_access_panel(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user)
            ->get('/panel')
            ->assertForbidden();
    }
}