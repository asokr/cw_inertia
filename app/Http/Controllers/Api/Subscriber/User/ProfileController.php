<?php

namespace App\Http\Controllers\Api\Subscriber\User;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\SubscribersSubscriptionsControl;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'name' => 'required|string|min:3|max:190',
            ],
            [
                'name.required' => 'Необходимо заполнить имя',
            ]
        );

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $user_id = Auth::id();

        $user = User::find($user_id);
        if ($user) {
            $user->name = $request->name;
            $user->save();
        }

        return response()->json(["success" => true, "messages" => ["Данные профиля обновлены"]], 200);
    }

    public function availablePlans()
    {

        $plans = SubscribersPlans::select([
            'id',
            'description',
            'duration',
            'name',
            'price',
            'limits_plan',
            'limits_month'
        ])->where(['status' => 1, 'hidden' => 0])->get()->toArray();
        $subs = auth()->user()->subscriber;
        $subsription = SubscribersSubscriptions::where([
            'subscribers_id' => $subs->id,
            'status' => 1,
        ])->first();
        $data = array();

        $data['plans'] = $plans;

        if ($subsription && $subsription->count()) {
            foreach ($data['plans'] as $key => $plan) {
                // План ниже текущего
                $data['plans'][$key]['lower'] = $plan['price'] < $subsription->plan->price;

                if ($plan['id'] === $subsription->plan_id) {
                    unset($data['plans'][$key]);
                }
            }

            $data['next'] = SubscribersSubscriptionsControl::select([
                'action'
            ])
                ->where([
                    'subscription_id' => $subsription->id,
                ])->get();
        }

        return response()->json(["success" => true, "messages" => ["Доступные тарифы получены"], "data" => $data], 200);
    }

    public function remainingLimits(Request $request)
    {

        $validator = Validator::make(
            $request->all(),
            [
                'limit' => 'required',
            ],
            [
                'limit.required' => 'Нужно передать лимит',
            ]
        );

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $subscriberId = auth()->user()->subscriber?->id;
        $subscription = $subscriberId
            ? SubscribersSubscriptions::where([
                'subscribers_id' => $subscriberId,
                'status' => 1,
            ])->first()
            : null;

        $data = (int) ($subscription?->getMonthLimit($request->limit) ?: 0);

        return response()->json(["success" => true, "messages" => ["Информация по лимиту"], "data" => $data], 200);
    }

    public function tourSeen(Request $request)
    {
        $user = $request->user();
        $user->has_seen_tour = true;
        $user->save();

        return response()->json(["success" => true, "messages" => ["Тур успешно отмечен как просмотренный"]], 200);
    }
}
