<?php

namespace Tests\Feature\Web\Errors;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class NotFoundPageTest extends WebAuthTestCase
{
    public function test_guest_sees_custom_not_found_page(): void
    {
        $this->get('/non-existent-page')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page
                ->component('Errors/NotFound')
                ->where('authenticated', false)
                ->where('homeUrl', '/login')
                ->where('isSubscriber', false)
                ->where('isAdmin', false));
    }

    public function test_subscriber_sees_tools_cta_on_not_found_page(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $user->assignRole('Подписчик');

        $this->actingAs($user)
            ->get('/cw-page')
            ->assertNotFound()
            ->assertInertia(fn ($page) => $page
                ->component('Errors/NotFound')
                ->where('authenticated', true)
                ->where('homeUrl', '/panel')
                ->where('isSubscriber', true)
                ->where('isAdmin', false));
    }
}