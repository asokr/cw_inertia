<?php

namespace Tests\Feature\Web\Auth;

use App\Models\User;

class LogoutTest extends WebAuthTestCase
{
    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}