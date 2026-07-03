<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\HomeRedirect;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VerificationController extends Controller
{
    public function notice(Request $request): Response|RedirectResponse
    {
        if ($request->user()?->hasVerifiedEmail()) {
            return redirect(HomeRedirect::forUser($request->user()));
        }

        return Inertia::render('Auth/VerifyEmail');
    }

    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect()
                ->route('login')
                ->with('error', 'Ссылка для подтверждения email неверная или просрочена');
        }

        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()
                ->route('login')
                ->with('error', 'Ссылка для подтверждения email неверная');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        if ($request->user()) {
            return redirect(HomeRedirect::forUser($user))
                ->with('success', 'Ваш Email подтверждён');
        }

        return redirect()
            ->route('login')
            ->with('success', 'Ваш Email подтверждён. Войдите в аккаунт.');
    }
}