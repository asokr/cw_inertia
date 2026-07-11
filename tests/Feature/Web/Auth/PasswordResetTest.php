<?php

namespace Tests\Feature\Web\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

class PasswordResetTest extends WebAuthTestCase
{
    public function test_forgot_password_page_renders(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_reset_password_notification_contains_web_reset_url(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
        ]);

        $notification = new ResetPasswordNotification('secret-token');
        $mail = $notification->toMail($user);

        $this->assertStringContainsString('/reset-password/secret-token', $mail->viewData['url']);
        $this->assertStringContainsString('email=reset%40example.com', $mail->viewData['url']);
        $this->assertStringNotContainsString('/auth/reset-password', $mail->viewData['url']);
    }

    public function test_user_can_request_password_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'reset@example.com',
        ]);

        $response = $this->post('/forgot-password', [
            'email' => 'reset@example.com',
        ]);

        $response->assertSessionHas('success');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_user_can_reset_password_via_web_form(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $token = Password::createToken($user);

        $response = $this->get("/reset-password/{$token}?email=reset%40example.com");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Auth/ResetPassword')
            ->where('token', $token)
            ->where('email', 'reset@example.com'));

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => 'reset@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('success');
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_legacy_auth_reset_password_url_redirects_to_web_route(): void
    {
        $response = $this->get('/auth/reset-password/legacy-token?email=reset%40example.com');

        $response->assertRedirect('/reset-password/legacy-token?email=reset%40example.com');
    }
}