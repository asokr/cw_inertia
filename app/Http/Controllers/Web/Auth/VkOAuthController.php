<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;
use App\Support\HomeRedirect;
use App\Services\Auth\VkAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class VkOAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $codeVerifier = Str::random(64);
        $state = Str::random(32);
        $deviceId = Str::uuid()->toString();
        $redirectUri = route('auth.vk.callback');

        Session::put('vk_oauth', [
            'code_verifier' => $codeVerifier,
            'state' => $state,
            'device_id' => $deviceId,
            'redirect_uri' => $redirectUri,
            'coupon_code' => $request->query('coupon_code'),
        ]);

        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.vk.client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => 'email',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return redirect('https://id.vk.com/authorize?' . $query);
    }

    public function callback(Request $request, VkAuthService $vkAuthService, AuthService $authService): RedirectResponse
    {
        $sessionData = Session::pull('vk_oauth', []);

        if (
            empty($sessionData['code_verifier'])
            || empty($sessionData['state'])
            || empty($sessionData['device_id'])
            || $request->query('state') !== $sessionData['state']
        ) {
            return redirect()->route('login')->withErrors([
                'email' => 'Некорректный OAuth state VK',
            ]);
        }

        if (! $request->filled('code')) {
            return redirect()->route('login')->withErrors([
                'email' => 'Авторизация VK отменена',
            ]);
        }

        $result = $vkAuthService->authenticate([
            'code' => $request->query('code'),
            'code_verifier' => $sessionData['code_verifier'],
            'device_id' => $sessionData['device_id'],
            'state' => $sessionData['state'],
            'redirect_uri' => $sessionData['redirect_uri'],
            'coupon_code' => $sessionData['coupon_code'] ?? null,
        ], $request->ip());

        if (! $result['success'] || ! $result['user']) {
            return redirect()->route('login')->withErrors([
                'email' => $result['errors'][0] ?? 'Ошибка авторизации VK',
            ]);
        }

        $authService->loginUser($result['user']);
        $request->session()->regenerate();

        return redirect(HomeRedirect::afterLogin($result['user']));
    }
}