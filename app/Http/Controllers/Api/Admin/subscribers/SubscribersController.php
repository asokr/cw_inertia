<?php

namespace App\Http\Controllers\Api\Admin\subscribers;

use Carbon\Carbon;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Traits\SubscriptionsTrait;
use App\Models\Subscribers\Subscribers;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use O21\Numeric\Numeric as NumericValue;
use App\Models\Subscribers\SubscribersPlans;
use App\Enums\SubscriptionsControlActionEnum;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\SubscribersSubscriptionsControl;
use O21\LaravelWallet\Models\Transaction;
use O21\LaravelWallet\Exception\InsufficientFundsException;

class SubscribersController extends Controller
{

    use SubscriptionsTrait;
    /**
     * Display a listing of the resource.
     */
    public function listSubscribers(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rows' => 'required|integer|min:1',
            'sortField' => 'nullable|string',
            'sortOrder' => 'nullable',
            'plan_id' => 'nullable|integer|exists:subscribers_plans,id',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $sortField = $request->has('sortField') ? $request->sortField : 'id';
        $sortOrder = $request->has('sortOrder') ? $request->sortOrder : '-1';

        $query = Subscribers::select([
            'id',
            'user_id',
            'status',
        ])
            ->with([
                'user' => function ($query) {
                    $query->select(['id', 'name', 'email', 'phone'])
                        ->with(['balances' => function ($query) {
                            $query->select(['payable_id', 'value', 'value_pending', 'value_on_hold']);
                        }]);
                }
            ])
            ->with([
                'subscriptions' => function ($query) {
                    $query->select(['subscribers_id', 'plan_id', 'limits_plan', 'limits_month', 'extra_limits_plan', 'extra_limits_month', 'start_date', 'end_date', 'status'])
                        ->with([
                            'plan' => function ($query) {
                                $query->select(['id', 'name', 'limits_plan', 'limits_month']);
                            }
                        ])
                        ->where('status', 1);
                }
            ]);

        if ($request->filled('plan_id')) {
            $planId = (int) $request->plan_id;
            $query->whereHas('subscriptions', function ($subQuery) use ($planId) {
                $subQuery->where('status', 1)->where('plan_id', $planId);
            });
        }

        $data = $query
            ->orderBy($sortField, $sortOrder == '1' ? 'asc' : 'desc')
            ->paginate($request->rows);


        return response()->json(["success" => true, "messages" => ["Список подписчиков"], 'data' => $data], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'plan_id' => 'required|exists:subscribers_plans,id',
            'start_date' => 'required|integer',
            'end_date' => 'required|string'
        ], [
            'required' => 'Не все данные указаны',
            'plan_id.exists' => 'Такого тарифа не существует',
            'duration.integer' => 'Продолжительность указывается в днях',
            'permissions.required' => 'Для тарифа необходимо хотя-бы одно разрешение',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $model = Subscribers::create([
            'user_id' => $request->user_id
        ]);

        if (!$model) {
            return response()->json(["success" => false, "messages" => ['Ошибка при добавлении подписчика']], 200);
        }

        return response()->json(["success" => true, "messages" => ["Подписчик добавлен"]], 200);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $subscriber = Subscribers::select([
            'id',
            'user_id',
            'status',
        ])
            ->with([
                'user' => function ($query) {
                    $query->select(['id', 'name', 'email', 'phone'])
                        ->with(['balances' => function ($query) {
                            $query->select(['payable_id', 'value', 'value_pending', 'value_on_hold']);
                        }]);
                }
            ])
            ->with([
                'subscriptions' => function ($query) {
                    $query->select(['id', 'subscribers_id', 'plan_id', 'limits_plan', 'extra_limits_plan', 'limits_month', 'extra_limits_month', 'start_date', 'end_date', 'status'])
                        ->with([
                            'plan' => function ($query) {
                                $query->select(['id', 'name', 'price', 'duration']);
                            }
                        ]);
                }
            ])
            ->where('id', $id)
            ->first();

        if (!$subscriber) {
            return response()->json(["success" => false, "messages" => ['Ничего не найдено']], 200);
        }

        $user = $subscriber->user;

        $transactionsCollection = Transaction::query()
            ->relatedTo($user)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $transactions = $transactionsCollection
            ->map(function (Transaction $transaction) use ($user) {
                $isIncoming = $transaction->to_id === $user->id && $transaction->to_type === $user->getMorphClass();

                $amount = (float) ($isIncoming ? $transaction->received : $transaction->amount);
                if (!$isIncoming) {
                    $amount = -$amount;
                }

                return [
                    'id' => $transaction->id,
                    'created_at' => optional($transaction->created_at)->format('d.m.Y H:i'),
                    'amount' => $amount,
                    'type' => $isIncoming ? 'deposit' : 'withdrawal',
                    'status' => $transaction->status,
                    'description' => data_get($transaction->meta, 'description'),
                    'transaction_id' => data_get($transaction->meta, 'transaction_id'),
                    'meta' => $transaction->meta,
                ];
            })->values();

        $totalDepositsRaw = Transaction::query()
            ->to($user)
            ->accountable()
            ->where('currency', 'RUB')
            ->sum('received');

        $totalDeposits = $this->normalizeNumeric($totalDepositsRaw);

        return response()->json([
            'success'  => true,
            'messages' => ['Информация о подписчике'],
            'data'     => $subscriber,
            'payments' => $transactions,
            'total_deposits' => $totalDeposits,
        ], 200);
    }

    public function deposit(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
        ], [
            'amount.required' => 'Укажите сумму пополнения',
            'amount.numeric' => 'Сумма должна быть числом',
            'amount.min' => 'Минимальная сумма пополнения — 1 рубль',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $subscriber = Subscribers::with(['user' => function ($query) {
            $query->select(['id', 'name', 'email', 'phone'])
                ->with(['balances' => function ($query) {
                    $query->select(['payable_id', 'value', 'value_pending', 'value_on_hold']);
                }]);
        }])->find($id);

        if (!$subscriber || !$subscriber->user) {
            return response()->json([
                'success' => false,
                'messages' => ['Подписчик не найден'],
            ], 200);
        }

        $adminUser = $request->user();
        $amount = round((float) $request->input('amount'), 2);

        if ($amount <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Сумма пополнения должна быть больше нуля'],
            ], 200);
        }

        $description = sprintf('Пополнение из админ панели (%s)', $adminUser?->email ?? 'system');

        $logContext = [
            'subscriber_id' => $subscriber->id,
            'user_id' => $subscriber->user->id,
            'admin_id' => $adminUser?->id,
            'admin_email' => $adminUser?->email,
            'amount' => $amount,
            'currency' => 'RUB',
        ];

        try {
            Log::channel('balance')->info('Admin manual deposit initiated', $logContext);

            $transaction = deposit($amount, 'RUB')
                ->to($subscriber->user)
                ->overcharge()
                ->meta([
                    'description' => $description,
                    'admin_user_id' => $adminUser?->id,
                    'admin_user_email' => $adminUser?->email,
                    'operation' => 'admin_manual_deposit',
                ])
                ->commit();

            if ($transaction === false) {
                throw new \RuntimeException('Не удалось создать транзакцию пополнения.');
            }

            $subscriber->user->refresh();

            $rawBalance = data_get($subscriber->user->balances, 'value');
            $balance = $this->normalizeNumeric($rawBalance);

            Log::channel('balance')->info('Admin manual deposit completed', array_merge($logContext, [
                'transaction_id' => method_exists($transaction, 'getKey') ? $transaction->getKey() : null,
                'balance_after' => $balance,
            ]));

            return response()->json([
                'success' => true,
                'messages' => ['Баланс пополнен'],
                'balance' => $balance,
            ], 200);
        } catch (\Throwable $exception) {
            Log::channel('balance')->error('Admin manual deposit failed', array_merge($logContext, [
                'exception' => $exception->getMessage(),
            ]));
            report($exception);

            return response()->json([
                'success' => false,
                'messages' => ['Не удалось пополнить баланс. Попробуйте ещё раз.'],
            ], 200);
        }
    }

    public function withdraw(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'comment' => 'required|string|max:500',
        ], [
            'amount.required' => 'Укажите сумму списания',
            'amount.numeric' => 'Сумма должна быть числом',
            'amount.min' => 'Минимальная сумма списания — 1 рубль',
            'comment.required' => 'Укажите комментарий списания',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $subscriber = Subscribers::with(['user' => function ($query) {
            $query->select(['id', 'name', 'email', 'phone'])
                ->with(['balances' => function ($query) {
                    $query->select(['payable_id', 'value', 'value_pending', 'value_on_hold']);
                }]);
        }])->find($id);

        if (! $subscriber || ! $subscriber->user) {
            return response()->json([
                'success' => false,
                'messages' => ['Подписчик не найден'],
            ], 200);
        }

        $adminUser = $request->user();
        $amount = round(abs((float) $request->input('amount')), 2);
        $comment = trim((string) $request->input('comment'));

        if ($amount <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Сумма списания должна быть больше нуля'],
            ], 200);
        }


        $logContext = [
            'subscriber_id' => $subscriber->id,
            'user_id' => $subscriber->user->id,
            'admin_id' => $adminUser?->id,
            'admin_email' => $adminUser?->email,
            'amount' => $amount,
            'currency' => 'RUB',
            'comment' => $comment,
        ];

        try {
            Log::channel('balance')->info('Admin manual withdrawal initiated', $logContext);

            $transaction = charge($amount, 'RUB')
                ->from($subscriber->user)
                ->meta([
                    'description' => $comment,
                    'admin_user_id' => $adminUser?->id,
                    'admin_user_email' => $adminUser?->email,
                    'operation' => 'admin_manual_withdrawal',
                ])
                ->commit();

            if ($transaction === false) {
                throw new \RuntimeException('Не удалось создать транзакцию списания.');
            }

            $subscriber->user->refresh();

            $rawBalance = data_get($subscriber->user->balances, 'value');
            $balance = $this->normalizeNumeric($rawBalance);

            Log::channel('balance')->info('Admin manual withdrawal completed', array_merge($logContext, [
                'transaction_id' => method_exists($transaction, 'getKey') ? $transaction->getKey() : null,
                'balance_after' => $balance,
            ]));

            return response()->json([
                'success' => true,
                'messages' => ['Баланс списан'],
                'balance' => $balance,
            ], 200);
        } catch (InsufficientFundsException $exception) {
            Log::channel('balance')->warning('Admin manual withdrawal failed: insufficient funds', array_merge($logContext, [
                'exception' => $exception->getMessage(),
            ]));

            return response()->json([
                'success' => false,
                'messages' => ['Недостаточно средств для списания'],
            ], 200);
        } catch (\Throwable $exception) {
            Log::channel('balance')->error('Admin manual withdrawal failed', array_merge($logContext, [
                'exception' => $exception->getMessage(),
            ]));
            report($exception);

            return response()->json([
                'success' => false,
                'messages' => ['Не удалось списать баланс. Попробуйте ещё раз.'],
            ], 200);
        }
    }

    public function reverseTransaction(Request $request, string $subscriberId, string $transactionId)
    {
        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:500',
        ], [
            'comment.required' => 'Укажите причину отмены',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $subscriber = Subscribers::with(['user' => function ($query) {
            $query->select(['id', 'name', 'email', 'phone'])
                ->with(['balances' => function ($query) {
                    $query->select(['payable_id', 'value', 'value_pending', 'value_on_hold']);
                }]);
        }])->find($subscriberId);

        if (! $subscriber || ! $subscriber->user) {
            return response()->json([
                'success' => false,
                'messages' => ['Подписчик не найден'],
            ], 200);
        }

        $user = $subscriber->user;

        $originalTransaction = Transaction::query()
            ->relatedTo($user)
            ->whereKey($transactionId)
            ->first();

        if (! $originalTransaction) {
            return response()->json([
                'success' => false,
                'messages' => ['Операция не найдена'],
            ], 200);
        }

        $originalMeta = (array) ($originalTransaction->meta ?? []);

        $operation = data_get($originalMeta, 'operation');

        $isIncoming = $originalTransaction->to_id === $user->id
            && $originalTransaction->to_type === $user->getMorphClass();

        if (! empty($originalMeta['reversal_transaction_id']) || $operation === 'admin_manual_reversal') {
            return response()->json([
                'success' => false,
                'messages' => ['Для этой операции уже создана отмена'],
            ], 200);
        }

        if ($isIncoming && $operation !== 'admin_manual_deposit') {
            return response()->json([
                'success' => false,
                'messages' => ['Отменить можно только ручные пополнения'],
            ], 200);
        }

        $amount = $this->normalizeNumeric($isIncoming ? $originalTransaction->received : $originalTransaction->amount);
        $amount = abs(round($amount, 2));

        if ($amount <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Сумма операции недоступна для отмены'],
            ], 200);
        }

        $comment = trim((string) $request->input('comment'));
        $adminUser = $request->user();

        $logContext = [
            'subscriber_id' => $subscriber->id,
            'user_id' => $user->id,
            'admin_id' => $adminUser?->id,
            'admin_email' => $adminUser?->email,
            'transaction_id' => $originalTransaction->id,
            'amount' => $amount,
            'currency' => $originalTransaction->currency,
            'comment' => $comment,
            'original_operation' => $operation,
        ];

        try {
            Log::channel('balance')->info('Admin manual reversal initiated', $logContext);

            $newTransaction = null;

            DB::transaction(function () use (&$newTransaction, $isIncoming, $amount, $user, $comment, $adminUser, $originalTransaction, &$originalMeta) {
                if ($isIncoming) {
                    $newTransaction = charge($amount, 'RUB')
                        ->from($user)
                        ->meta([
                            'description' => $comment,
                            'admin_user_id' => $adminUser?->id,
                            'admin_user_email' => $adminUser?->email,
                            'operation' => 'admin_manual_reversal',
                            'reversed_transaction_id' => $originalTransaction->id,
                            'reversal_of' => 'deposit',
                        ])
                        ->commit();
                } else {
                    $newTransaction = deposit($amount, 'RUB')
                        ->to($user)
                        ->overcharge()
                        ->meta([
                            'description' => $comment,
                            'admin_user_id' => $adminUser?->id,
                            'admin_user_email' => $adminUser?->email,
                            'operation' => 'admin_manual_reversal',
                            'reversed_transaction_id' => $originalTransaction->id,
                            'reversal_of' => 'withdrawal',
                        ])
                        ->commit();
                }

                if ($newTransaction === false) {
                    throw new \RuntimeException('Не удалось создать транзакцию отмены.');
                }

                $originalMeta['reversal_transaction_id'] = $newTransaction->id;
                $originalMeta['reversal_comment'] = $comment;
                $originalMeta['reversal_admin_user_id'] = $adminUser?->id;
                $originalMeta['reversal_admin_user_email'] = $adminUser?->email;
                $originalTransaction->meta = $originalMeta;
                $originalTransaction->save();
            });

            $user->refresh();

            $rawBalance = data_get($user->balances, 'value');
            $balance = $this->normalizeNumeric($rawBalance);

            Log::channel('balance')->info('Admin manual reversal completed', array_merge($logContext, [
                'new_transaction_id' => $newTransaction && method_exists($newTransaction, 'getKey') ? $newTransaction->getKey() : null,
                'balance_after' => $balance,
            ]));

            return response()->json([
                'success' => true,
                'messages' => ['Операция отменена'],
                'balance' => $balance,
            ], 200);
        } catch (InsufficientFundsException $exception) {
            Log::channel('balance')->warning('Admin manual reversal failed: insufficient funds', array_merge($logContext, [
                'exception' => $exception->getMessage(),
            ]));

            return response()->json([
                'success' => false,
                'messages' => ['Недостаточно средств для отмены операции'],
            ], 200);
        } catch (\Throwable $exception) {
            Log::channel('balance')->error('Admin manual reversal failed', array_merge($logContext, [
                'exception' => $exception->getMessage(),
            ]));
            report($exception);

            return response()->json([
                'success' => false,
                'messages' => ['Не удалось выполнить отмену. Попробуйте ещё раз.'],
            ], 200);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'user.name' => 'required',
            'user.email' => 'required|email',
            'user_id' => 'required|exists:users,id',
            'plan' => 'exists:subscribers_plans,id',
        ], [
            'plan.exists' => 'Такого тарифа не существует',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $user = User::find($request->user_id);
        $user->name = $request->user['name'];
        $user->email = $request->user['email'];
        $user->save();

        if (is_array($request->subscriptions)) {
            foreach ($request->subscriptions as $subscriptionPayload) {
                $subscriptionId = data_get($subscriptionPayload, 'id');
                $extraLimits = data_get($subscriptionPayload, 'extra_limits_month');

                if (!$subscriptionId || !is_array($extraLimits)) {
                    continue;
                }

                $subscription = SubscribersSubscriptions::query()
                    ->where('subscribers_id', $id)
                    ->where('id', $subscriptionId)
                    ->first();

                if (!$subscription) {
                    continue;
                }

                $normalized = [];
                foreach ($extraLimits as $limitName => $limitValue) {
                    if ($limitName === null || $limitName === '') {
                        continue;
                    }

                    if (is_array($limitValue)) {
                        $limitValue = reset($limitValue);
                    }

                    $normalized[$limitName] = max(0, (int) ($limitValue ?? 0));
                }

                if (($subscription->extra_limits_month ?? []) !== $normalized) {
                    $subscription->extra_limits_month = $normalized;
                    $subscription->save();
                }
            }
        }

        if ($request->plan && !empty($request->plan)) {
            $plan = SubscribersPlans::find($request->plan);
            $subscription = SubscribersSubscriptions::where('subscribers_id', $id)->first();
            if (!$subscription) {
                // Если нет подписки - просто подпишем юзера
                $end_date = Carbon::now()->addDays($plan->duration);

                $permissions = array_merge($plan->permissions, ['subscriber']);
                $user->syncPermissions($permissions);
                SubscribersSubscriptions::create([
                    'subscribers_id' => $id,
                    'plan_id' => $request->plan,
                    'limits_month' => $plan->limits_month,
                    'limits_plan' => $plan->limits_plan,
                    'end_date' => $end_date,
                    'status' => 1
                ]);

                // Если удалили подписку, и снова подписали, синхронизируем лимиты плана
                foreach ($plan->limits_plan as $limit_name => $limit_count) {
                    $this->syncLimits($id, $limit_name);
                }
            } else if ($plan->price > $subscription->plan->price) {
                // Тут мы переводим юзера на более высокий тариф

                // Если уже есть другая задача на понижение - удалим
                SubscribersSubscriptionsControl::where([
                    'subscription_id' => $subscription->id,
                    'action' => SubscriptionsControlActionEnum::LOWER
                ])->delete();

                // Посчитаем сколько дней добавить к новому тарифу, учитывая, сколько осталось у старого
                $endDate = Carbon::createFromDate($subscription->end_date);
                $remainingDays = $endDate->diffInDays(Carbon::now());
                $newDayCost = $plan->price / $plan->duration;
                $oldDayCost = $subscription->plan->price / $subscription->plan->duration;
                $oldRemainingValue = $remainingDays * $oldDayCost;
                $addDaysToPlan = round($oldRemainingValue / $newDayCost);

                $remainingMonthLimits = array();
                // Посчитаем оставшиеся лимиты в месяц
                foreach ($plan->limits_month as $key => $value) {
                    if (isset($subscription->limits_month[$key])) {
                        $remainingMonthLimits[$key] = (int) $value + (int) $subscription->limits_month[$key];
                    } else {
                        $remainingMonthLimits[$key] = (int) $value;
                    }
                }
                $remainingPlanLimits = array();
                // Пересчитаем лимиты по тарифу
                foreach ($plan->limits_plan as $key => $value) {

                    $planCount = $this->getUsedLimits($id, $key);
                    if ($planCount) {
                        $remainingPlanLimits[$key] = (int) $value - (int) $planCount;
                        if ($remainingPlanLimits[$key] < 0) {
                            return response()->json(["success" => false, "messages" => ['Не хватает лимита: ' . $key]], 200);
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

                return response()->json(["success" => true, "messages" => ['План сменится на более низкий по окончании текущего перода']], 200);
            }
        }

        return response()->json(["success" => true, "messages" => ["Подписчик обновлён"]], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        // $model = Subscribers::destroy($id);

        // if (!$model) {
        //     return response()->json(["success" => false, "messages" => ['Подписчика нет']], 200);
        // }

        return response()->json(["success" => true, "messages" => ["Подписчик удалён"]], 200);
    }


    public function findSubscriber(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|min:2',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $query = $request->q;

        $data = array();

        $users = User::select([
            'id',
        ])
            ->where('users.name', 'like', "%{$query}%")
            ->orWhere('users.surname', 'like', "%{$query}%")
            ->orWhere('users.email', 'like', "%{$query}%")
            ->get();

        foreach ($users as $user) {
            $model = Subscribers::where('user_id', $user->id)
                ->select([
                    'id',
                    'user_id',
                    'status',
                ])
                ->with([
                    'user' => function ($query) {
                        $query->select(['id', 'name', 'email', 'phone'])
                            ->with([
                                'balances' => function ($query) {
                                    $query->select(['payable_id', 'value', 'value_pending', 'value_on_hold']);
                                }
                            ]);
                    }
                ])
                ->with([
                    'subscriptions' => function ($query) {
                        $query->select(['subscribers_id', 'plan_id', 'limits_plan', 'limits_month', 'extra_limits_plan', 'extra_limits_month', 'start_date', 'end_date', 'status'])
                            ->with([
                                'plan' => function ($query) {
                                    $query->select(['id', 'name', 'limits_plan', 'limits_month']);
                                }
                            ])
                            ->where('status', 1);
                    }
                ])->first();

            if ($model) {
                $data[] = $model;
            }
        }

        return response()->json(["success" => true, "messages" => ["Список пользователей"], 'data' => $data], 200);
    }


    private function normalizeNumeric(mixed $value): float
    {
        if ($value instanceof NumericValue) {
            return (float) $value->get();
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
