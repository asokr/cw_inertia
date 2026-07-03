<?php

namespace App\Services\Subscriber;

use App\Models\ExtraLimits;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExtraLimitPurchaseService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCatalog(): array
    {
        return ExtraLimits::select(['id', 'price', 'limit_name', 'quantity'])
            ->orderBy('order')
            ->get()
            ->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUserExtraLimits(User $user): ?array
    {
        $subscriberId = $user->subscriberId();

        if (! $subscriberId) {
            return null;
        }

        $data = SubscribersSubscriptions::select(['extra_limits_month'])
            ->where('subscribers_id', $subscriberId)
            ->first();

        return $data?->extra_limits_month;
    }

    /**
     * @return array{success: bool, messages: array<int, string>, data?: array<string, mixed>}
     */
    public function purchase(User $user, int $extraLimitId): array
    {
        $extraLimits = ExtraLimits::find($extraLimitId);

        if (! $extraLimits) {
            return ['success' => false, 'messages' => ['Ошибка в данных']];
        }

        $logContext = [
            'user_id' => $user->id,
            'subscription_id' => null,
            'extra_limit_id' => $extraLimits->id,
            'limit_name' => $extraLimits->limit_name,
            'quantity' => $extraLimits->quantity,
            'price' => $extraLimits->price,
            'currency' => 'RUB',
        ];

        if (! $user->isEnoughFunds($extraLimits->price, 'RUB')) {
            Log::channel('balance')->warning('Extra limit purchase aborted: insufficient funds', $logContext);

            return ['success' => false, 'messages' => ['Недостаточно средств']];
        }

        $subscription = $user->getSubscriptions();
        if (! $subscription) {
            return ['success' => false, 'messages' => ['У вас нет активной подписки']];
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

                $charge = charge($extraLimits->price, 'RUB')->from($user)->meta([
                    'description' => "Покупка дополнительного лимита {$limitKey}: было {$previousQuantity}, купили {$purchasedQuantity}, стало {$updatedQuantity}",
                ])->commit();

                if ($charge === false) {
                    throw new \RuntimeException('Не удалось списать средства.');
                }

                $data = $subscriptionExtraLimits;
            });
        } catch (\Throwable $exception) {
            Log::channel('balance')->error('Extra limit purchase failed', array_merge($logContext, [
                'exception' => $exception->getMessage(),
            ]));
            report($exception);

            return ['success' => false, 'messages' => ['Не удалось оформить покупку. Попробуйте ещё раз']];
        }

        return [
            'success' => true,
            'messages' => ['Дополнительные лимиты добавлены'],
            'data' => $data,
        ];
    }
}