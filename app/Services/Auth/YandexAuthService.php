<?php

namespace App\Services\Auth;

use App\Models\Subscribers\Subscribers;
use App\Models\User;
use App\Services\CouponService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class YandexAuthService
{
    public function __construct(
        private readonly CouponService $couponService,
    ) {
    }

    /**
     * @param  array{code: string, redirect_uri: string, code_verifier?: ?string, coupon_code?: ?string}  $payload
     * @return array{success: bool, user: ?User, errors: array<int, string>}
     */
    public function authenticate(array $payload, ?string $ip = null): array
    {
        try {
            $client = new Client();

            $tokenFormParams = [
                'grant_type' => 'authorization_code',
                'code' => $payload['code'],
                'client_id' => config('services.yandex.client_id'),
                'client_secret' => config('services.yandex.client_secret'),
                'redirect_uri' => $payload['redirect_uri'],
            ];

            if (! empty($payload['code_verifier'])) {
                $tokenFormParams['code_verifier'] = (string) $payload['code_verifier'];
            }

            $tokenResponse = $client->post('https://oauth.yandex.ru/token', [
                'http_errors' => false,
                'form_params' => $tokenFormParams,
            ]);

            $tokenData = json_decode($tokenResponse->getBody()->getContents(), true);
            $tokenStatusCode = $tokenResponse->getStatusCode();

            if ($tokenStatusCode >= 400 || ! isset($tokenData['access_token'])) {
                Log::error('Yandex Auth Token Error', ['status' => $tokenStatusCode, 'response' => $tokenData]);

                return [
                    'success' => false,
                    'user' => null,
                    'errors' => ['Не удалось получить токен Яндекс'],
                ];
            }

            $userResponse = $client->get('https://login.yandex.ru/info', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'OAuth ' . $tokenData['access_token'],
                ],
                'query' => [
                    'format' => 'json',
                ],
            ]);

            $yandexUser = json_decode($userResponse->getBody()->getContents(), true);

            if ($userResponse->getStatusCode() >= 400 || ! $yandexUser || ! isset($yandexUser['id'])) {
                Log::error('Yandex Auth User Info Error', ['response' => $yandexUser]);

                return [
                    'success' => false,
                    'user' => null,
                    'errors' => ['Не удалось получить данные пользователя Яндекс'],
                ];
            }

            $user = $this->findOrCreateUser($yandexUser, $payload['coupon_code'] ?? null, $ip);

            return [
                'success' => true,
                'user' => $user,
                'errors' => [],
            ];
        } catch (GuzzleException $e) {
            Log::error('Yandex Auth Guzzle Error: ' . $e->getMessage());

            return [
                'success' => false,
                'user' => null,
                'errors' => ['Ошибка соединения с Яндекс'],
            ];
        } catch (\Throwable $e) {
            Log::error('Yandex Auth Error: ' . $e->getMessage());

            return [
                'success' => false,
                'user' => null,
                'errors' => ['Внутренняя ошибка сервера'],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $yandexUser
     */
    private function findOrCreateUser(array $yandexUser, ?string $couponCode, ?string $ip): User
    {
        $yandexId = (string) $yandexUser['id'];
        $email = $yandexUser['default_email'] ?? ($yandexUser['emails'][0] ?? ($yandexUser['email'] ?? null));

        $user = User::where('yandex_id', $yandexId)->first();

        if (! $user && $email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->update(['yandex_id' => $yandexId]);
            }
        }

        if ($user) {
            return $user;
        }

        if (! $email) {
            $email = 'yandex_' . $yandexId . '@yandex.placeholder.com';
        }

        $user = User::create([
            'name' => $yandexUser['first_name'] ?? ($yandexUser['display_name'] ?? 'Yandex User'),
            'surname' => $yandexUser['last_name'] ?? '',
            'email' => $email,
            'password' => Hash::make(Str::random(16)),
            'yandex_id' => $yandexId,
            'phone' => $yandexUser['default_phone']['number'] ?? null,
        ]);

        $user->markEmailAsVerified();

        Subscribers::create(['user_id' => $user->id]);
        $user->assignRole('Подписчик');
        $user->plan_id = 2;

        if ($couponCode) {
            try {
                $coupon = $this->couponService->validateCoupon($couponCode);
                $user->plan_id = $coupon->value;
                $this->couponService->minusCouponLimit($coupon);
                $this->couponService->recordCouponUsage($user, $coupon, [
                    'source' => 'yandex_registration',
                    'ip' => $ip,
                ]);
            } catch (\Throwable) {
            }
        }

        $user->save();

        event(new Registered($user));

        return $user;
    }
}