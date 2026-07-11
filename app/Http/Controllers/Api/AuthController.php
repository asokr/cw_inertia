<?php

namespace App\Http\Controllers\Api;

use Log;
use Carbon\Carbon;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use App\Services\CouponService;
use App\Jobs\SendContactFormEmail;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Services\SubscriptionService;
use Illuminate\Auth\Events\Registered;
use App\Models\Subscribers\Subscribers;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{

    protected $subsriptionService;

    public function __construct(SubscriptionService $subsriptionService)
    {
        $this->subsriptionService = $subsriptionService;
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:190',
            'password' => 'required|string|between:6,190'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, "messages" => $validator->errors()->all()], 200);
        }

        $data = [
            'email' => $request->email,
            'password' => $request->password,
        ];

        if (auth()->attempt($data)) {
            if (!auth()->user()->hasVerifiedEmail()) {
                return response()->json(['verify' => true, "data" => auth()->user()->id], 401);
            }
            $token = auth()->user()->createToken('CreativeWebOffice')->accessToken;
            return response()->json(['access_token' => $token], 200);
        } else {
            return response()->json(["errors" => ["Не верный логин или пароль"]], 401);
        }
    }

    public function register(Request $request, CouponService $couponService)
    {
        $messages = [
            'email.unique' => 'Этот E-mail уже занят',
            'email.email' => 'Похоже, что E-mail ненастоящий!',
            'password.confirmed' => 'Пароли не совпадают',
            'password.between' => 'Пароль должен состоять минимум из 6 символов',
            'required' => 'Все поля обязательны для заполнения',
            'phone.required' => 'Телефон обязателен для заполнения',
            'phone.regex' => 'Некорректный формат телефона. Используйте формат: +1234567890',
        ];

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:190|unique:users',
            'password' => 'required|string|between:6,190|confirmed',
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'coupon_code' => ''
        ], $messages);

        if ($validator->fails()) {
            return response()->json(['success' => false, "messages" => $validator->errors()->all()], 200);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone ?? null,
            'password' => Hash::make($request->password),
        ]);

        if (!$user) {
            return response()->json(["errors" => ["Ошибка регистрации"]], 422);
        }

        Subscribers::create([
            'user_id' => $user->id,
        ]);
        $user->assignRole('Подписчик');

        if ($request->coupon_code) {
            try {
                $coupon = $couponService->validateCoupon($request->coupon_code);
                $couponService->minusCouponLimit($coupon);
                $couponService->recordCouponUsage($user, $coupon, [
                    'source' => 'registration',
                    'ip' => $request->ip(),
                ]);
            } catch (\Throwable $th) {
            }
        }

        event(new Registered($user));

        return response()->json(['success' => true, "messages" => ["Можете войти под своим логином и паролем"], "data" => $user->id]);
    }


    public function getPermissions()
    {

        $user = auth()->guard('api')->check() ? auth()->guard('api')->user() : null;

        if (!$user) {
            return [];
        }

        $permissions = $user->jsPermissions() ? $user->jsPermissions() : null;

        if ($permissions) {
            $permissions = json_decode($permissions);
        }

        if (!empty($user['roles']) && !empty($user['roles'][0]) && $user['roles'][0]->name == 'super-admin') {
            $permissions->permissions = Permission::all()->pluck('name');
        }

        return response()->json(['permissions' => $permissions], 200);
    }


    public function getUserDetails()
    {
        $user = auth()->guard('api')->check() ? auth()->guard('api')->user() : null;

        if ($user) {

            $user->verified = auth()->user()->hasVerifiedEmail();
        }

        return response()->json(['success' => true, "messages" => ["Данные и текущем пользователе получены"], "data" => $user], 200);
    }

    public function nuxtUserInfo()
    {

        $user = auth()->guard('api')->check() ? auth()->guard('api')->user() : null;

        if ($user) {
            $roles = $user->getRoleNames();

            $roles_names = array();
            foreach ($roles as $name) {
                $roles_names[] = $name;
            }
            unset($user->roles);

            $user->roles = $roles_names;

            $user->verified = auth()->user()->hasVerifiedEmail();

            $subscription = $user->getSubscriptions();
            if ($subscription && $subscription->status == 1) {
                $this->subsriptionService->setSubscription($subscription);
                $this->subsriptionService->checkAndManageSubscription();
            } else {
                $user->notify = [
                    'text' => '<a href="/panel/user/profile">Пополните баланс и продлите подписку</a>',
                    'type' => 'info',
                    'title' => 'Действие вашего тарифа окончено'
                ];
            }

            if ($subscription && $subscription->plan_id == 2) {
                if ($subscription->status) {
                    // Уведомление для демо тарифа
                    $options = [
                        'join' => ' ',
                        'parts' => 2,
                        'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                    ];
                    $end_date = Carbon::parse($subscription->end_date);
                    $user->notify = [
                        'text' => 'Спасибо за регистрацию. Вам предоставлен пробный период. Осталось: ' . $end_date->diffForHumans(Carbon::now(), $options),
                        'type' => 'info',
                        'title' => 'У Вас пробный период'
                    ];
                } else {
                    $user->notify = [
                        'text' => 'Для дальнейшей работы <a href="/panel/user/profile">выберите тариф</a>',
                        'type' => 'info',
                        'title' => 'Тестовый период завершен'
                    ];
                }
            }

            $permissions = $user->getPermissionNames();
            unset($user->permissions);
            $user->permissions = $permissions;
            $user->balance = $user->balance()->value->get();

            return response()->json($user, 200);
        }
        return null;
    }

    public function sendMessage(Request $request)
    {
        $data = [];

        // Добавляем имя и почту, если есть авторизация
        if (auth()->check()) {
            $data['Имя и почта'] = auth()->user()->getFullName();
        }

        // Добавляем все переданные поля формы (игнорируем пустые)
        foreach ($request->all() as $key => $value) {
            if (!empty($value) && $key !== 'subject') {
                $data[ucfirst($key)] = $value;
            }
        }

        try {
            SendContactFormEmail::dispatchSync(
                'info@cwplatform.ru',
                $request->subject ?? 'Новая заявка с сайта cwplatform.ru',
                $data
            );

            return response()->json([
                'success' => true,
                'messages' => ['Сообщение успешно отправлено'],
            ], 200);
        } catch (\Throwable $th) {
            \Log::error('Ошибка при отправке почты', [
                'error' => $th->getMessage(),
                'data' => $data,
            ]);

            return response()->json([
                'success' => false,
                'messages' => ['Ошибка при отправке сообщения, попробуйте позже.'],
            ], 500);
        }
    }
    public function test() {}
}
