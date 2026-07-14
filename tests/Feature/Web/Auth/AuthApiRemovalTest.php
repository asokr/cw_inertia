<?php

namespace Tests\Feature\Web\Auth;

class AuthApiRemovalTest extends WebAuthTestCase
{
    public function test_legacy_auth_api_routes_are_removed(): void
    {
        $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ])->assertNotFound();

        $this->postJson('/api/register', [
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->postJson('/api/auth/vk', ['code' => 'x'])->assertNotFound();
        $this->postJson('/api/auth/yandex', ['code' => 'x'])->assertNotFound();

        $this->getJson('/api/get-permissions')->assertNotFound();
        $this->getJson('/api/get-current-user')->assertNotFound();

        $this->postJson('/api/forgot-password', ['email' => 'test@example.com'])->assertNotFound();
        $this->postJson('/api/reset-password', ['email' => 'test@example.com'])->assertNotFound();

        $this->getJson('/api/email/verify/1')->assertNotFound();
        $this->postJson('/api/email/resend', ['id' => 1])->assertNotFound();

        $this->postJson('/api/send-message', ['name' => 'Test'])->assertNotFound();

        $this->postJson('/api/services/wb-search/webhook', [])->assertNotFound();
    }
}