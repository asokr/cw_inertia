<?php

namespace Tests\Feature\Web;

use App\Jobs\SendContactFormEmail;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Web\Auth\WebAuthTestCase;

class SupportMessageTest extends WebAuthTestCase
{
    public function test_authenticated_user_can_send_support_message(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'name' => 'Иван',
            'email' => 'ivan@example.com',
        ]);

        $response = $this->actingAs($user)->post('/support-message', [
            'name' => 'Иван',
            'phone' => '+79991234567',
            'message' => 'Не приходит письмо подтверждения',
            'source' => 'verify_email',
            'context_email' => 'ivan@example.com',
        ]);

        $response->assertSessionHas('success');

        Bus::assertDispatched(SendContactFormEmail::class, function (SendContactFormEmail $job) {
            return true;
        });
    }

    public function test_support_message_validation_errors_are_returned(): void
    {
        $response = $this->post('/support-message', [
            'name' => '',
            'phone' => '123',
            'message' => '',
        ]);

        $response->assertSessionHasErrors(['name', 'phone', 'message']);
    }
}