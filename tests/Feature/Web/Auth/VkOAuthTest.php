<?php

namespace Tests\Feature\Web\Auth;

use App\Models\User;
use App\Services\Auth\VkAuthService;
use Illuminate\Support\Str;

class VkOAuthTest extends WebAuthTestCase
{
    public function test_vk_redirect_stores_oauth_session_and_redirects_to_vk(): void
    {
        config(['services.vk.client_id' => '12345']);

        $response = $this->get('/auth/vk/redirect');

        $response->assertRedirect();
        $this->assertStringStartsWith('https://id.vk.com/authorize?', $response->headers->get('Location'));
        $this->assertNotEmpty(session('vk_oauth.code_verifier'));
        $this->assertNotEmpty(session('vk_oauth.state'));
        $this->assertArrayNotHasKey('device_id', session('vk_oauth'));
    }

    public function test_vk_callback_uses_device_id_from_query(): void
    {
        config(['services.vk.client_id' => '12345']);

        $state = Str::random(32);
        $codeVerifier = Str::random(64);

        $user = User::factory()->create();
        $user->assignRole('Подписчик');

        $this->mock(VkAuthService::class, function ($mock) use ($user): void {
            $mock->shouldReceive('authenticate')
                ->once()
                ->withArgs(function (array $payload): bool {
                    return $payload['device_id'] === 'vk-device-from-callback';
                })
                ->andReturn([
                    'success' => true,
                    'user' => $user,
                    'errors' => [],
                ]);
        });

        $response = $this->withSession([
            'vk_oauth' => [
                'code_verifier' => $codeVerifier,
                'state' => $state,
                'redirect_uri' => route('auth.vk.callback'),
            ],
        ])->get('/auth/callback/vk?' . http_build_query([
            'code' => 'auth-code',
            'state' => $state,
            'device_id' => 'vk-device-from-callback',
        ]));

        $response->assertRedirect('/panel');
        $this->assertAuthenticatedAs($user);
    }

    public function test_vk_callback_requires_device_id_from_query(): void
    {
        config(['services.vk.client_id' => '12345']);

        $state = Str::random(32);

        $response = $this->withSession([
            'vk_oauth' => [
                'code_verifier' => Str::random(64),
                'state' => $state,
                'redirect_uri' => route('auth.vk.callback'),
            ],
        ])->get('/auth/callback/vk?' . http_build_query([
            'code' => 'auth-code',
            'state' => $state,
        ]));

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}