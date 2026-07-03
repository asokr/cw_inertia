<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Auth\LoginRequest;
use App\Services\Auth\AuthService;
use App\Support\HomeRedirect;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'vkEnabled' => (bool) config('services.vk.client_id'),
            'yandexEnabled' => (bool) config('services.yandex.client_id'),
        ]);
    }

    public function store(LoginRequest $request, AuthService $authService): RedirectResponse
    {
        $result = $authService->attemptLogin(
            $request->validated('email'),
            $request->validated('password'),
        );

        if ($result['needs_verification']) {
            return redirect()
                ->route('verification.notice')
                ->with('messages', ['Подтвердите email перед входом'])
                ->with('verification_email', $request->validated('email'));
        }

        if (! $result['success']) {
            return back()->withErrors([
                'email' => $result['errors'][0] ?? 'Ошибка входа',
            ]);
        }

        $request->session()->regenerate();

        return redirect(HomeRedirect::afterLogin($request->user()));
    }
}