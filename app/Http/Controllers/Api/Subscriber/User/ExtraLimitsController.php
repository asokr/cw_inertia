<?php

namespace App\Http\Controllers\Api\Subscriber\User;

use App\Models\ExtraLimits;
use App\Models\Subscribers\SubscribersSubscriptions;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ExtraLimitsController extends Controller
{
    public function index()
    {
        $data = ExtraLimits::select([
            'id',
            'price',
            'limit_name',
            'quantity'
        ])->orderBy('order')->get()->toArray();

        return response()->json(["success" => true, "messages" => ["Список лимитов"], 'data' => $data], 200);
    }


    public function buyExtraLimits(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'id' => 'required|exists:extra_limits,id',
            ],
            [
                'id.required' => 'Недостаточно данных',
                'id.exists' => 'Ошибка в данных',
            ]
        );

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $extraLimits = ExtraLimits::find($request->id);
        $user = auth()->user();

        $logContext = [
            'user_id' => $user->id,
            'subscription_id' => null,
            'extra_limit_id' => $extraLimits->id,
            'limit_name' => $extraLimits->limit_name,
            'quantity' => $extraLimits->quantity,
            'price' => $extraLimits->price,
            'currency' => 'RUB',
        ];

        if (!$user->isEnoughFunds($extraLimits->price, 'RUB')) {
            Log::channel('balance')->warning('Extra limit purchase aborted: insufficient funds', $logContext);
            return response()->json(["success" => false, "messages" => ['Недостаточно средств']], 200);
        }

        $subscription = $user->getSubscriptions();
        if (!$subscription) {
            return response()->json(["success" => false, "messages" => ['У вас нет активной подписки']], 200);
        }

        $logContext['subscription_id'] = $subscription->id;

        Log::channel('balance')->info('Extra limit purchase initiated', $logContext);

        $data = [];

        try {
            DB::transaction(function () use (&$data, $subscription, $extraLimits, $user, $logContext) {
                $subscriptionExtraLimits = $subscription->extra_limits_month ?? [];

                $limitKey = $extraLimits->limit_name;
                $previousQuantity = (int) ($subscriptionExtraLimits[$limitKey] ?? 0);
                $purchasedQuantity = (int) $extraLimits->quantity;
                $updatedQuantity = $previousQuantity + $purchasedQuantity;

                $subscriptionExtraLimits[$limitKey] = $updatedQuantity;

                $subscription->extra_limits_month = $subscriptionExtraLimits;
                $subscription->save();

                Log::channel('balance')->info('Extra limits updated before charge', array_merge($logContext, [
                    'updated_extra_limits_month' => $subscriptionExtraLimits,
                    'limit_quantity_before' => $previousQuantity,
                    'limit_quantity_after' => $updatedQuantity,
                    'limit_quantity_purchased' => $purchasedQuantity,
                ]));

                $charge = charge($extraLimits->price, 'RUB')->from($user)->meta([
                    'description' => "Покупка дополнительного лимита {$limitKey}: было {$previousQuantity}, купили {$purchasedQuantity}, стало {$updatedQuantity}"
                ])->commit();

                if ($charge === false) {
                    throw new \RuntimeException('Не удалось списать средства.');
                }

                Log::channel('balance')->info('Charge committed for extra limit purchase', array_merge($logContext, [
                    'balance_transaction_id' => $charge->getKey() ?? null,
                    'limit_quantity_before' => $previousQuantity,
                    'limit_quantity_after' => $updatedQuantity,
                    'limit_quantity_purchased' => $purchasedQuantity,
                ]));

                $data = $subscriptionExtraLimits;
            });
        } catch (\Throwable $exception) {
            Log::channel('balance')->error('Extra limit purchase failed', array_merge($logContext, [
                'exception' => $exception->getMessage(),
            ]));
            report($exception);
            return response()->json([
                "success" => false,
                "messages" => ["Не удалось оформить покупку. Попробуйте ещё раз"],
            ], 200);
        }

        Log::channel('balance')->info('Extra limit purchase completed', array_merge($logContext, [
            'resulting_extra_limits_month' => $data,
        ]));

        return response()->json(["success" => true, "messages" => ["Дополнительные лимиты добавлены"], 'data' => $data], 200);
    }

    public function userExtraLimits()
    {
        $subscribers_id = auth()->user()->subscriber->id;
        $data = SubscribersSubscriptions::select([
            'extra_limits_month',
        ])->where('subscribers_id', $subscribers_id)->first();

        return response()->json(["success" => true, "messages" => ["Список лимитов"], 'data' => $data], 200);
    }
}
