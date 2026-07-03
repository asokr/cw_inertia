<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;
use App\Support\HomeRedirect;
use App\Services\Auth\YandexAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class YandexOAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(32);
        $redirectUri = route('auth.yandex.callback');

        Session::put('yandex_oauth', [
            'state' => $state,
            'redirect_uri' => $redirectUri,
            'coupon_code' => $request->query('coupon_code'),
        ]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.yandex.client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return redirect('https://oauth.yandex.ru/authorize?' . $query);
    }

    public function callback(Request $request, YandexAuthService $yandexAuthService, AuthService $authService): RedirectResponse
    {
        $sessionData = Session::pull('yandex_oauth', []);

        if (
            empty($sessionData['state'])
            || $request->query('state') !== $sessionData['state']
        ) {
            return redirect()->route('login')->withErrors([
                'email' => 'Некорректный OAuth state Яндекс',
            ]);
        }

        if (! $request->filled('code')) {
            return redirect()->route('login')->withErrors([
                'email' => 'Авторизация Яндекс отменена',
            ]);
        }

        $result = $yandexAuthService->authenticate([
            'code' => $request->query('code'),
            'redirect_uri' => $sessionData['redirect_uri'],
            'coupon_code' => $sessionData['coupon_code'] ?? null,
        ], $request->ip());

        if (! $result['success'] || ! $result['user']) {
            return redirect()->route('login')->withErrors([
                'email' => $result['errors'][0] ?? 'Ошибка авторизации Яндекс',
            ]);
        }

        $authService->loginUser($result['user']);
        $request->session()->regenerate();

        return redirect(HomeRedirect::afterLogin($result['user']));
    }
}