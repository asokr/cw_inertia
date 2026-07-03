<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscribers\Subscribers;
use App\Models\User;
use App\Services\CouponService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class YandexAuthController extends Controller
{
    /**
     * Авторизация через Yandex OAuth
     *
     * @param Request $request
     * @param CouponService $couponService
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request, CouponService $couponService)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'redirect_uri' => 'required|string',
            'code_verifier' => 'nullable|string',
            'coupon_code' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 422);
        }

        try {
            $client = new Client();

            $tokenFormParams = [
                'grant_type' => 'authorization_code',
                'code' => $request->code,
                'client_id' => config('services.yandex.client_id'),
                'client_secret' => config('services.yandex.client_secret'),
                'redirect_uri' => $request->redirect_uri,
            ];

            if ($request->filled('code_verifier')) {
                $tokenFormParams['code_verifier'] = (string) $request->code_verifier;
            }

            $tokenResponse = $client->post('https://oauth.yandex.ru/token', [
                'http_errors' => false,
                'form_params' => $tokenFormParams,
            ]);

            $tokenData = json_decode($tokenResponse->getBody()->getContents(), true);
            $tokenStatusCode = $tokenResponse->getStatusCode();

            if ($tokenStatusCode >= 400 || ! isset($tokenData['access_token'])) {
                $error = (string) ($tokenData['error'] ?? '');
                $errorDescription = (string) ($tokenData['error_description'] ?? '');

                Log::error('Yandex Auth Token Error', ['status' => $tokenStatusCode, 'response' => $tokenData]);

                if ($error === 'access_denied' || Str::contains(Str::lower($errorDescription), 'access denied')) {
                    return response()->json(['success' => false, 'messages' => ['Пользователь отменил авторизацию Яндекс']], 401);
                }

                if ($error === 'invalid_grant' && Str::contains(Str::lower($errorDescription), 'code_verifier')) {
                    return response()->json(['success' => false, 'messages' => ['Неверный code_verifier для Яндекс OAuth (PKCE)']], 401);
                }

                if ($error === 'invalid_grant' || Str::contains(Str::lower($errorDescription), 'code')) {
                    return response()->json(['success' => false, 'messages' => ['Неверный код авторизации Яндекс']], 401);
                }

                if ($tokenStatusCode >= 500) {
                    return response()->json(['success' => false, 'messages' => ['Ошибка Яндекс API']], 502);
                }

                return response()->json(['success' => false, 'messages' => ['Не удалось получить токен Яндекс']], 401);
            }

            $accessToken = $tokenData['access_token'];

            $userResponse = $client->get('https://login.yandex.ru/info', [
                'http_errors' => false,
                'headers' => [
                    'Authorization' => 'OAuth ' . $accessToken,
                ],
                'query' => [
                    'format' => 'json',
                ],
            ]);

            $yandexUser = json_decode($userResponse->getBody()->getContents(), true);
            $userStatusCode = $userResponse->getStatusCode();

            if ($userStatusCode >= 400) {
                Log::error('Yandex Auth User Info HTTP Error', ['status' => $userStatusCode, 'response' => $yandexUser]);
                return response()->json(['success' => false, 'messages' => ['Ошибка Яндекс API']], 502);
            }

            if (! $yandexUser || ! isset($yandexUser['id'])) {
                Log::error('Yandex Auth User Info Error', ['response' => $yandexUser]);
                return response()->json(['success' => false, 'messages' => ['Не удалось получить данные пользователя Яндекс']], 401);
            }

            $yandexId = (string) $yandexUser['id'];
            $email = $yandexUser['default_email'] ?? ($yandexUser['emails'][0] ?? ($yandexUser['email'] ?? null));

            Log::info('Yandex User Data', $yandexUser);

            $user = User::where('yandex_id', $yandexId)->first();

            if (! $user && $email) {
                $user = User::where('email', $email)->first();
                if ($user) {
                    $user->update(['yandex_id' => $yandexId]);
                }
            }

            if (! $user) {
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

                if (! $user) {
                    return response()->json(['errors' => ['Ошибка регистрации']], 422);
                }

                $user->markEmailAsVerified();

                Subscribers::create([
                    'user_id' => $user->id,
                ]);

                $user->assignRole('Подписчик');

                $user->plan_id = 2;

                if ($request->coupon_code) {
                    try {
                        $coupon = $couponService->validateCoupon($request->coupon_code);

                        $user->plan_id = $coupon->value;

                        $couponService->minusCouponLimit($coupon);

                        $couponService->recordCouponUsage($user, $coupon, [
                            'source' => 'yandex_registration',
                            'ip' => $request->ip(),
                        ]);
                    } catch (\Throwable $th) {
                        // Игнорируем ошибку купона, продолжаем с тестовым тарифом.
                    }
                }

                event(new Registered($user));
            }

            $token = $user->createToken('CreativeWebOffice')->accessToken;

            return response()->json([
                'access_token' => $token,
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                    'name' => $user->name,
                ],
            ], 200);
        } catch (GuzzleException $e) {
            Log::error('Yandex Auth Guzzle Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'messages' => ['Ошибка соединения с Яндекс']], 500);
        } catch (\Exception $e) {
            Log::error('Yandex Auth Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'messages' => ['Внутренняя ошибка сервера']], 500);
        }
    }
}
