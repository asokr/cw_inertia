<?php

namespace App\Http\Controllers\Api\Subscriber\User;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Traits\SubscriptionsTrait;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\SubscribersPlans;
use App\Enums\SubscriptionsControlActionEnum;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\SubscribersSubscriptionsControl;

class SubscriptionsController extends Controller
{
    use SubscriptionsTrait;
    public function index()
    {
        $user = auth()->user();

        $subscription = SubscribersSubscriptions::select([
            'id',
            'subscribers_id',
            'plan_id',
            'status',
            'limits_plan',
            'limits_month',
            'extra_limits_month',
            'start_date',
            'end_date'
        ])
            ->where([
                'subscribers_id' => $user->subscriber->id,
                'status' => 1
            ])
            ->first();

        if (!$subscription) {
            return response()->json(["success" => true, "messages" => ["Данные пользователя получены"], "data" => []], 200);
        }
        $plan = SubscribersPlans::select([
            'id',
            'price',
            'description',
            'name',
            'limits_plan',
            'limits_month',
        ])
            ->where('id', $subscription->plan_id)
            ->first();

        $next = SubscribersSubscriptionsControl::select([
            'action'
        ])
            ->where([
                'subscription_id' => $subscription->id,
            ])->get();

        $data = [
            'subscription' => $subscription,
            'plan' => $plan,
            'next' => $next
        ];

        return response()->json(["success" => true, "messages" => ["Данные пользователя получены"], "data" => $data], 200);
    }


    public function unsubscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'exists:subscribers_subscriptions,id',
        ], [
            'id.required' => 'Не указан идентификатор подписки'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()->all()], 422);
        }

        $user = auth()->user();
        $subscription = SubscribersSubscriptions::find($request->id);
        $belongs = $subscription->subscribers_id == $user->subscriber->id;
        if (!$belongs)
            return response()->json(["success" => false, "messages" => ["Это не ваша подписка"]], 200);

        $model = SubscribersSubscriptionsControl::create([
            'subscription_id' => $request->id,
            'action' => SubscriptionsControlActionEnum::STOP
        ]);


        if (!$model) {
            return response()->json(["success" => false, "messages" => ['Не удалось отменить подписку']], 200);
        }

        return response()->json(["success" => true, "messages" => ["Подписка отменена"]], 200);
    }

    public function resubscribe(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'exists:subscribers_subscriptions,id',
        ], [
            'id.required' => 'Не указан идентификатор подписки'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()->all()], 422);
        }

        $user = auth()->user();
        $subscription = SubscribersSubscriptions::find($request->id);
        $belongs = $subscription->subscribers_id == $user->subscriber->id;
        if (!$belongs)
            return response()->json(["success" => false, "messages" => ["Это не ваша подписка"]], 200);

        $model = SubscribersSubscriptionsControl::where([
            'subscription_id' => $request->id,
            'action' => SubscriptionsControlActionEnum::STOP
        ])->delete();


        if (!$model) {
            return response()->json(["success" => false, "messages" => ['Не удалось возобновить подписку']], 200);
        }

        return response()->json(["success" => true, "messages" => ["Подписка возобновлена"]], 200);
    }

    public function changePlan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'exists:subscribers_plans,id',
        ], [
            'plan_id.exists' => 'Такого тарифа не существует',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $user = auth()->user();
        $plan = SubscribersPlans::find($request->plan_id);
        $subscription = $user->getSubscriptions();
        $next = [];
        $messages = ["Вы перешли на новый тариф"];


        if (!$subscription) {
            // Если нет подписки
            // проверим баланс
            if (!$user->isEnoughFunds($plan->price, 'RUB')) {
                return response()->json(["success" => false, "messages" => ['Недостаточно средств для перехода']], 200);
            }

            $end_date = Carbon::now()->addDays($plan->duration);

            // Подпишем юзера
            $user->givePermissionTo($plan->permissions);
            $subscription = SubscribersSubscriptions::create([
                'subscribers_id' => $user->subscriber->id,
                'plan_id' => $plan->id,
                'limits_month' => $plan->limits_month,
                'limits_plan' => $plan->limits_plan,
                'end_date' => $end_date,
                'status' => 1
            ]);

            if (!$subscription) {
                return response()->json(["success" => false, "messages" => ['Что-то пошло не так']], 200);
            }
            // На всякий случай, синхронизируем лимиты плана.
            foreach ($plan->limits_plan as $limit_name => $limit_count) {
                $this->syncLimits($user->subscriber->id, $limit_name);
            }
            // Снимим средства с баланса
            charge($plan->price, 'RUB')->from($user)->commit();

            return response()->json(["success" => true, "messages" => ["Тариф выбран"]], 200);
        } else if (!$subscription->status) {
            // Если подписка не активна и юзер меняет тариф
            // проверим баланс
            if (!$user->isEnoughFunds($plan->price, 'RUB')) {
                return response()->json(["success" => false, "messages" => ['Недостаточно средств для перехода']], 200);
            }

            $end_date = Carbon::now()->addDays($plan->duration);

            // Подпишем юзера
            $user->givePermissionTo($plan->permissions);
            $status = $subscription->update([
                'plan_id' => $plan->id,
                'limits_month' => $plan->limits_month,
                'limits_plan' => $plan->limits_plan,
                'end_date' => $end_date,
                'status' => 1
            ]);

            if (!$status) {
                return response()->json(["success" => false, "messages" => ['Что-то пошло не так']], 200);
            }
            // На всякий случай, синхронизируем лимиты плана.
            foreach ($plan->limits_plan as $limit_name => $limit_count) {
                $this->syncLimits($user->subscriber->id, $limit_name);
            }
            // Снимим средства с баланса
            charge($plan->price, 'RUB')->from($user)->meta([
                'description' => "Активация подписки с тарифом {$plan->name}",
            ])->commit();

            return response()->json(["success" => true, "messages" => ["Тариф активен"]], 200);
        } else if ($plan->price >= $subscription->plan->price) {
            // Тут мы переводим юзера на более высокий тариф

            // проверим баланс
            if (!$user->isEnoughFunds($plan->price, 'RUB')) {
                return response()->json(["success" => false, "messages" => ['Недостаточно средств']], 200);
            }

            // Если уже есть другая задача на понижение - удалим
            SubscribersSubscriptionsControl::where([
                'subscription_id' => $subscription->id,
                'action' => SubscriptionsControlActionEnum::LOWER
            ])->delete();

            // Посчитаем сколько дней добавить к новому тарифу, учитывая, сколько осталось у старого
            $endDate = Carbon::createFromDate($subscription->end_date);

            $remainingDays = round(Carbon::now()->diffInDays($endDate));

            $newDayCost = $plan->price / $plan->duration;
            $oldDayCost = $subscription->plan->price / $subscription->plan->duration;
            $oldRemainingValue = $remainingDays * $oldDayCost;
            $addDaysToPlan = round($oldRemainingValue / $newDayCost);


            $remainingMonthLimits = [];
            // Посчитаем оставшиеся лимиты в месяц
            foreach ($plan->limits_month as $key => $value) {
                $remainingMonthLimits[$key] = isset($subscription->limits_month[$key])
                    ? (int) $value + (int) $subscription->limits_month[$key]
                    :
                    (int) $value;
            }
            $remainingPlanLimits = [];
            // Пересчитаем лимиты по тарифу
            foreach ($plan->limits_plan as $key => $value) {
                $planCount = $this->getUsedLimits($user->subscriber->id, $key);
                if ($planCount) {
                    $remainingPlanLimits[$key] = (int) $value - (int) $planCount;
                    // Как правило, выше тариф - больше лимит Плана, но на всякий случай, проверим,
                    // и выкинем нас, если лимита плана не хватает
                    // Пока не продаём доп лимиты на план, когда будем продавать тут можно будет всё
                    // посчитать
                    if ($remainingPlanLimits[$key] < 0) {
                        return response()->json(["success" => false, "messages" => ['Не хватает лимита']], 200);
                    }
                } else {
                    $remainingPlanLimits[$key] = (int) $value;
                }
            }

            $subscription->plan_id = $plan->id;
            $subscription->limits_plan = $remainingPlanLimits;
            $subscription->limits_month = $remainingMonthLimits;
            $subscription->start_date = Carbon::now();
            $subscription->end_date = Carbon::now()->addDays($plan->duration + $addDaysToPlan);
            $subscription->save();

            $user->syncPermissions($plan->permissions);

            // Снимим средства с баланса
            charge($plan->price, 'RUB')->from($user)->commit();
        } else if ($plan->price < $subscription->plan->price) {
            // Если уже есть другая задача на понижение - удалим
            SubscribersSubscriptionsControl::where([
                'subscription_id' => $subscription->id,
                'action' => SubscriptionsControlActionEnum::LOWER
            ])->delete();

            $model = SubscribersSubscriptionsControl::create([
                'subscription_id' => $subscription->id,
                'action' => SubscriptionsControlActionEnum::LOWER,
                'config' => ['plan_id' => $plan->id],
            ]);

            if (!$model) {
                return response()->json(["success" => false, "messages" => ['Не удалось отменить подписку']], 200);
            }

            $next = ['action' => SubscriptionsControlActionEnum::LOWER];

            $messages = ['Тариф сменится на более низкий по окончании текущего перода'];
        }

        $data = [
            'subscription' => $subscription,
            'plan' => $plan,
            'next' => $next,
        ];

        return response()->json(["success" => true, "messages" => $messages, "data" => $data], 200);
    }
}
