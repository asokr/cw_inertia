<?php

namespace Tests\Feature\Web\Auth;

use App\Mail\EmailVerification;
use App\Models\User;
use Illuminate\Support\Facades\URL;

class EmailVerificationTest extends WebAuthTestCase
{
    public function test_verification_email_contains_web_verify_url(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'verify@example.com',
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(120),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ]
        );

        $mail = new EmailVerification($url, $user);
        $mail->build();

        $expectedPath = '/email/verify/'.$user->id.'/'.sha1($user->email);

        $this->assertStringContainsString($expectedPath, $mail->verifyUrl);
        $this->assertStringNotContainsString('/auth/verify-email', $mail->verifyUrl);
        $this->assertStringNotContainsString('/api/email/verify', $mail->verifyUrl);
    }

    public function test_user_can_verify_email_via_signed_link(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'verify@example.com',
        ]);

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(120),
            [
                'id' => $user->id,
                'hash' => sha1($user->email),
            ]
        );

        $response = $this->get($url);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('success');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }
}