<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscribers\Subscribers;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Services\CouponService;

class VkAuthController extends Controller
{
    /**
     * Авторизация через VK ID
     *
     * @param Request $request
     * @param CouponService $couponService
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request, CouponService $couponService)
    {
        // 1. Валидация входных данных
        $validator = Validator::make($request->all(), [
            'code'          => 'required|string',
            'code_verifier' => 'required|string',
            'device_id'     => 'required|string',
            'state'         => 'required|string',
            'redirect_uri'  => 'required|string',
            'coupon_code'   => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 422);
        }

        try {
            // 2. Обмен кода на токен
            $client = new Client();
            $tokenResponse = $client->post('https://id.vk.com/oauth2/auth', [
                'form_params' => [
                    'grant_type'    => 'authorization_code',
                    'code'          => $request->code,
                    'code_verifier' => $request->code_verifier,
                    'client_id'     => config('services.vk.client_id'),
                    'device_id'     => $request->device_id,
                    'redirect_uri'  => $request->redirect_uri,
                    'state'         => $request->state,
                ]
            ]);

            $tokenData = json_decode($tokenResponse->getBody()->getContents(), true);

            if (!isset($tokenData['access_token'])) {
                Log::error('VK Auth Token Error: ' . json_encode($tokenData));
                return response()->json(['success' => false, 'messages' => ['Не удалось получить токен VK']], 401);
            }

            $accessToken = $tokenData['access_token'];

            // 3. Получение данных пользователя
            $userResponse = $client->post('https://id.vk.com/oauth2/user_info', [
                'form_params' => [
                    'access_token' => $accessToken,
                    'client_id'    => config('services.vk.client_id'),
                ]
            ]);

            $userData = json_decode($userResponse->getBody()->getContents(), true);
            $vkUser = $userData['user'] ?? null;

            if (!$vkUser || !isset($vkUser['user_id'])) {
                Log::error('VK Auth User Info Error: ' . json_encode($userData));
                return response()->json(['success' => false, 'messages' => ['Не удалось получить данные пользователя VK']], 401);
            }

            $vkId = (string) $vkUser['user_id'];
            $email = $vkUser['email'] ?? null;

            Log::info('VK User Data: ', $vkUser);

            // 4. Поиск или создание пользователя
            $user = User::where('vk_id', $vkId)->first();

            if (!$user && $email) {
                // Если нет по vk_id, ищем по email
                $user = User::where('email', $email)->first();
                if ($user) {
                    // Нашли по email, привязываем vk_id
                    $user->update(['vk_id' => $vkId]);
                }
            }

            if (!$user) {
                // Новый пользователь
                if (!$email) {
                    // Если email не пришел от ВК, генерируем фейковый
                    $email = 'vk_' . $vkId . '@vk.placeholder.com';
                }

                // Логика регистрации (как в AuthController::register)
                $user = User::create([
                    'name'              => $vkUser['first_name'] ?? 'VK User',
                    'surname'           => $vkUser['last_name'] ?? '',
                    'email'             => $email,
                    'password'          => Hash::make(Str::random(16)), // Случайный пароль
                    'vk_id'             => $vkId,
                    'phone'             => $vkUser['phone'] ?? null,
                ]);

                if (!$user) {
                    return response()->json(["errors" => ["Ошибка регистрации"]], 422);
                }

                // Помечаем email как подтвержденный (доверие к VK)
                $user->markEmailAsVerified();

                Subscribers::create([
                    'user_id' => $user->id,
                ]);

                $user->assignRole('Подписчик');

                if ($request->coupon_code) {
                    try {
                        $coupon = $couponService->validateCoupon($request->coupon_code);
                        $couponService->minusCouponLimit($coupon);
                        $couponService->recordCouponUsage($user, $coupon, [
                            'source' => 'vk_registration',
                            'ip' => $request->ip(),
                        ]);
                    } catch (\Throwable $th) {
                    }
                }

                event(new Registered($user));
            }

            // 5. Авторизация и ответ
            $token = $user->createToken('CreativeWebOffice')->accessToken;

            return response()->json([
                'access_token' => $token,
                'user' => [
                    'id'    => $user->id,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                    'name'  => $user->name,
                ]
            ], 200);
        } catch (GuzzleException $e) {
            Log::error('VK Auth Guzzle Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'messages' => ['Ошибка соединения с VK']], 500);
        } catch (\Exception $e) {
            Log::error('VK Auth Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'messages' => ['Внутренняя ошибка сервера']], 500);
        }
    }
}
