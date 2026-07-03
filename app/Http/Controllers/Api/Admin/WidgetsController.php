<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersSubscriptions;

class WidgetsController extends Controller
{
    public function lastSubscriptions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rows' => 'required',
            'sortField' => '',
            'sortOrder' => ''
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $sortField = $request->has('sortField') ? $request->sortField : 'renewed_at';
        $sortOrder = $request->has('sortOrder') ? $request->sortOrder : '-1';
        $sortDirection = $sortOrder == '1' ? 'asc' : 'desc';

        // Получаем последние продления из таблицы транзакций
        $query = DB::table('transactions')
            ->where(function ($q) {
                $q->where('meta', 'like', '%Продление подписки%')
                    ->orWhere('meta', 'like', '%Активация подписки%')
                    ->orWhere('meta', 'like', '%Продление тарифа%');
            })
            ->where('status', 'success')
            ->where('from_type', 'App\Models\User');

        if ($sortField === 'renewed_at') {
            $query->orderBy('created_at', $sortDirection);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $renewalTransactions = $query
            ->select(['from_id as user_id', 'created_at as renewed_at', 'amount', 'meta'])
            ->paginate($request->rows);

        // Собираем user_id из транзакций
        $userIds = collect($renewalTransactions->items())->pluck('user_id')->unique()->toArray();

        // Загружаем подписки с подписчиками по user_id
        $subscriptions = SubscribersSubscriptions::select([
            'subscribers_subscriptions.id',
            'subscribers_subscriptions.subscribers_id',
            'subscribers_subscriptions.plan_id',
            'subscribers_subscriptions.start_date',
            'subscribers_subscriptions.end_date',
        ])
            ->whereHas('subscriber', function ($query) use ($userIds) {
                $query->whereIn('user_id', $userIds);
            })
            ->with([
                'subscriber' => function ($query) {
                    $query->select(['subscribers.id', 'subscribers.user_id'])
                        ->with([
                            'user' => function ($query) {
                                $query->select(['users.id', 'users.name', 'users.email', 'users.created_at', 'users.phone'])
                                    ->with([
                                        'balances' => function ($query) {
                                            $query->select(['balances.payable_id', 'balances.value']);
                                        }
                                    ]);
                            }
                        ]);
                },
                'plan' => function ($query) {
                    $query->select(['subscribers_plans.id', 'subscribers_plans.name', 'subscribers_plans.price']);
                },
                'couponUsage' => function ($query) {
                    $query->where('used_at', '>=', now()->subDays(30))
                        ->select(['coupon_usages.id', 'coupon_usages.coupon_id', 'coupon_usages.user_id', 'coupon_usages.used_at'])
                        ->with([
                            'coupon' => function ($query) {
                                $query->select(['coupons.id', 'coupons.code', 'coupons.type', 'coupons.value']);
                            }
                        ]);
                }
            ])
            ->get()
            ->keyBy(fn($sub) => $sub->subscriber->user_id ?? null);

        // Формируем результат с датой продления из транзакции
        $items = collect($renewalTransactions->items())->map(function ($transaction) use ($subscriptions) {
            $subscription = $subscriptions->get($transaction->user_id);
            if (!$subscription) {
                return null;
            }

            $data = $subscription->toArray();
            $data['renewed_at'] = $transaction->renewed_at;
            $meta = json_decode($transaction->meta, true);
            $data['transaction_description'] = $meta['description'] ?? null;

            return $data;
        })->filter()->values();

        return response()->json([
            "success" => true,
            "messages" => 'Данные получены',
            'data' => [
                'data' => $items,
                'total' => $renewalTransactions->total(),
                'current_page' => $renewalTransactions->currentPage(),
                'per_page' => $renewalTransactions->perPage(),
                'last_page' => $renewalTransactions->lastPage(),
            ]
        ], 200);
    }

    public function lastRegistered(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rows' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $rows = $request->input('rows', 10);
        $page = $request->input('page', 1);

        $subscribers = Subscribers::select(['id', 'user_id', 'created_at'])
            ->with([
                'user' => function ($query) {
                    $query->select(['id', 'name', 'email', 'email_verified_at', 'created_at', 'vk_id', 'yandex_id'])
                        ->with([
                            'balances' => function ($balanceQuery) {
                                $balanceQuery->select(['payable_id', 'value']);
                            }
                        ]);
                },
                'subscriptions' => function ($query) {
                    $query->select([
                        'id',
                        'subscribers_id',
                        'plan_id',
                        'limits_plan',
                        'extra_limits_plan',
                        'limits_month',
                        'extra_limits_month',
                        'start_date',
                        'status'
                    ])
                        ->where('status', 1)
                        ->latest('start_date')
                        ->with([
                            'plan' => function ($planQuery) {
                                $planQuery->select(['id', 'name', 'limits_plan', 'limits_month']);
                            },
                            'couponUsage' => function ($couponQuery) {
                                $couponQuery->where('used_at', '>=', now()->subDays(30))
                                    ->select(['coupon_usages.id', 'coupon_usages.coupon_id', 'coupon_usages.user_id', 'coupon_usages.used_at'])
                                    ->with([
                                        'coupon' => function ($coupon) {
                                            $coupon->select(['coupons.id', 'coupons.code', 'coupons.type', 'coupons.value']);
                                        }
                                    ]);
                            }
                        ]);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->paginate($rows, ['*'], 'page', $page);

        $data = $subscribers->getCollection()->map(function ($subscriber) {
            $subscription = $subscriber->subscriptions->first();
            $plan = $subscription?->plan;
            $rawBalance = $subscriber->user?->balances?->value;
            $balance = is_numeric($rawBalance) ? (float) $rawBalance : 0;

            return [
                'id' => $subscriber->id,
                'name' => $subscriber->user?->name,
                'email' => $subscriber->user?->email,
                'vk_id' => $subscriber->user?->vk_id,
                'yandex_id' => $subscriber->user?->yandex_id,
                'registered_at' => $subscriber->created_at,
                'is_verified' => (bool) optional($subscriber->user)->email_verified_at,
                'plan' => $plan ? [
                    'id' => $plan->id,
                    'name' => $plan->name,
                ] : null,
                'balance' => $balance,
                'coupon_usage' => $subscription?->couponUsage,
                'limits_plan' => $subscription?->limits_plan ?? [],
                'extra_limits_plan' => $subscription?->extra_limits_plan ?? [],
                'limits_month' => $subscription?->limits_month ?? [],
                'extra_limits_month' => $subscription?->extra_limits_month ?? [],
            ];
        });

        $subscribers->setCollection($data);

        return response()->json(["success" => true, "messages" => ["Данные получены"], 'data' => $subscribers], 200);
    }
}
