<?php

namespace Tests\Feature\Web\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class LoginTest extends WebAuthTestCase
{
    public function test_login_page_renders(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    public function test_subscriber_can_login_and_is_redirected_to_panel(): void
    {
        $user = User::factory()->create([
            'email' => 'subscriber@example.com',
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('Подписчик');

        $response = $this->post('/login', [
            'email' => 'subscriber@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect('/panel');
        $this->assertAuthenticatedAs($user);
    }

    public function test_subscriber_login_ignores_intended_dashboard_url(): void
    {
        $user = User::factory()->create([
            'email' => 'subscriber@example.com',
            'password' => Hash::make('password'),
        ]);
        $user->assignRole('Подписчик');

        $response = $this->withSession(['url.intended' => '/dashboard'])
            ->post('/login', [
                'email' => 'subscriber@example.com',
                'password' => 'password',
            ]);

        $response->assertRedirect('/panel');
    }

    public function test_user_without_role_is_redirected_to_login(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect('/login');
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_unverified_user_is_redirected_to_verification_notice(): void
    {
        User::factory()->unverified()->create([
            'email' => 'user@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->post('/login', [
            'email' => 'user@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();
    }
}