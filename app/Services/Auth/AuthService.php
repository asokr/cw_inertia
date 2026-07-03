<?php

namespace App\Services\Auth;

use App\Models\Subscribers\Subscribers;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(
        private readonly CouponService $couponService,
    ) {
    }

    /**
     * @return array{success: bool, user: ?User, needs_verification: bool, errors: array<int, string>}
     */
    public function attemptLogin(string $email, string $password): array
    {
        $credentials = [
            'email' => $email,
            'password' => $password,
        ];

        if (! Auth::attempt($credentials)) {
            return [
                'success' => false,
                'user' => null,
                'needs_verification' => false,
                'errors' => ['Неверный логин или пароль'],
            ];
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->hasVerifiedEmail()) {
            Auth::logout();

            return [
                'success' => false,
                'user' => $user,
                'needs_verification' => true,
                'errors' => ['Подтвердите email перед входом'],
            ];
        }

        return [
            'success' => true,
            'user' => $user,
            'needs_verification' => false,
            'errors' => [],
        ];
    }

    /**
     * @param  array{name: string, email: string, password: string, phone?: ?string, coupon_code?: ?string}  $data
     * @return array{success: bool, user: ?User, errors: array<int, string>, messages: array<int, string>}
     */
    public function register(array $data, ?string $ip = null): array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
        ]);

        if (! $user) {
            return [
                'success' => false,
                'user' => null,
                'errors' => ['Ошибка регистрации'],
                'messages' => [],
            ];
        }

        Subscribers::create([
            'user_id' => $user->id,
        ]);

        $user->assignRole('Подписчик');
        $user->plan_id = 2;

        if (! empty($data['coupon_code'])) {
            try {
                $coupon = $this->couponService->validateCoupon($data['coupon_code']);
                $user->plan_id = $coupon->value;
                $this->couponService->minusCouponLimit($coupon);
                $this->couponService->recordCouponUsage($user, $coupon, [
                    'source' => 'registration',
                    'ip' => $ip,
                ]);
            } catch (\Throwable) {
            }
        }

        $user->save();

        event(new Registered($user));

        return [
            'success' => true,
            'user' => $user,
            'errors' => [],
            'messages' => ['Регистрация успешна. Подтвердите email и войдите в аккаунт.'],
        ];
    }

    public function loginUser(User $user): void
    {
        Auth::login($user);
    }

    public function logout(): void
    {
        Auth::logout();
    }
}