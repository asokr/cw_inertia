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

class VkAuthService
{
    public function __construct(
        private readonly CouponService $couponService,
    ) {
    }

    /**
     * @param  array{code: string, code_verifier: string, device_id: string, state: string, redirect_uri: string, coupon_code?: ?string}  $payload
     * @return array{success: bool, user: ?User, errors: array<int, string>}
     */
    public function authenticate(array $payload, ?string $ip = null): array
    {
        try {
            $client = new Client();
            $tokenResponse = $client->post('https://id.vk.com/oauth2/auth', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $payload['code'],
                    'code_verifier' => $payload['code_verifier'],
                    'client_id' => config('services.vk.client_id'),
                    'device_id' => $payload['device_id'],
                    'redirect_uri' => $payload['redirect_uri'],
                    'state' => $payload['state'],
                ],
            ]);

            $tokenData = json_decode($tokenResponse->getBody()->getContents(), true);

            if (! isset($tokenData['access_token'])) {
                Log::error('VK Auth Token Error', ['response' => $tokenData]);

                return [
                    'success' => false,
                    'user' => null,
                    'errors' => ['Не удалось получить токен VK'],
                ];
            }

            $userResponse = $client->post('https://id.vk.com/oauth2/user_info', [
                'form_params' => [
                    'access_token' => $tokenData['access_token'],
                    'client_id' => config('services.vk.client_id'),
                ],
            ]);

            $userData = json_decode($userResponse->getBody()->getContents(), true);
            $vkUser = $userData['user'] ?? null;

            if (! $vkUser || ! isset($vkUser['user_id'])) {
                Log::error('VK Auth User Info Error', ['response' => $userData]);

                return [
                    'success' => false,
                    'user' => null,
                    'errors' => ['Не удалось получить данные пользователя VK'],
                ];
            }

            $user = $this->findOrCreateUser($vkUser, $payload['coupon_code'] ?? null, $ip);

            return [
                'success' => true,
                'user' => $user,
                'errors' => [],
            ];
        } catch (GuzzleException $e) {
            Log::error('VK Auth Guzzle Error: ' . $e->getMessage());

            return [
                'success' => false,
                'user' => null,
                'errors' => ['Ошибка соединения с VK'],
            ];
        } catch (\Throwable $e) {
            Log::error('VK Auth Error: ' . $e->getMessage());

            return [
                'success' => false,
                'user' => null,
                'errors' => ['Внутренняя ошибка сервера'],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $vkUser
     */
    private function findOrCreateUser(array $vkUser, ?string $couponCode, ?string $ip): User
    {
        $vkId = (string) $vkUser['user_id'];
        $email = $vkUser['email'] ?? null;

        $user = User::where('vk_id', $vkId)->first();

        if (! $user && $email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                $user->update(['vk_id' => $vkId]);
            }
        }

        if ($user) {
            return $user;
        }

        if (! $email) {
            $email = 'vk_' . $vkId . '@vk.placeholder.com';
        }

        $user = User::create([
            'name' => $vkUser['first_name'] ?? 'VK User',
            'surname' => $vkUser['last_name'] ?? '',
            'email' => $email,
            'password' => Hash::make(Str::random(16)),
            'vk_id' => $vkId,
            'phone' => $vkUser['phone'] ?? null,
        ]);

        $user->markEmailAsVerified();

        Subscribers::create(['user_id' => $user->id]);
        $user->assignRole('Подписчик');

        if ($couponCode) {
            try {
                $coupon = $this->couponService->validateCoupon($couponCode);
                $this->couponService->minusCouponLimit($coupon);
                $this->couponService->recordCouponUsage($user, $coupon, [
                    'source' => 'vk_registration',
                    'ip' => $ip,
                ]);
            } catch (\Throwable) {
            }
        }

        event(new Registered($user));

        return $user;
    }
}