<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Auth\RegisterRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register', [
            'vkEnabled' => (bool) config('services.vk.client_id'),
            'yandexEnabled' => (bool) config('services.yandex.client_id'),
        ]);
    }

    public function store(RegisterRequest $request, AuthService $authService): RedirectResponse
    {
        $result = $authService->register($request->validated(), $request->ip());

        if (! $result['success']) {
            return back()->withErrors([
                'email' => $result['errors'][0] ?? 'Ошибка регистрации',
            ]);
        }

        $authService->loginUser($result['user']);
        $request->session()->regenerate();

        return redirect()
            ->route('verification.notice')
            ->with('messages', $result['messages'])
            ->with('verification_email', $request->validated('email'));
    }
}