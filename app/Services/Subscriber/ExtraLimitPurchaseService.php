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
        return ExtraLimits::query()
            ->select(['id', 'slug', 'name', 'price', 'order'])
            ->orderBy('order')
            ->orderBy('id')
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
    public function purchase(User $user, int $extraLimitId, int $quantity): array
    {
        $extraLimits = ExtraLimits::find($extraLimitId);

        if (! $extraLimits) {
            return ['success' => false, 'messages' => ['Ошибка в данных']];
        }

        if ($quantity < 1) {
            return ['success' => false, 'messages' => ['Укажите количество']];
        }

        $unitPrice = (float) $extraLimits->price;
        $total = round($quantity * $unitPrice, 2);
        $slug = (string) $extraLimits->slug;
        $displayName = (string) $extraLimits->name;

        $logContext = [
            'user_id' => $user->id,
            'subscription_id' => null,
            'extra_limit_id' => $extraLimits->id,
            'slug' => $slug,
            'name' => $displayName,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $total,
            'currency' => 'RUB',
        ];

        if (! $user->isEnoughFunds($total, 'RUB')) {
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
            DB::transaction(function () use (&$data, $subscription, $extraLimits, $user, $quantity, $total, $slug, $displayName, $unitPrice, $logContext) {
                $subscriptionExtraLimits = $subscription->extra_limits_month ?? [];
                $previousQuantity = (int) ($subscriptionExtraLimits[$slug] ?? 0);
                $updatedQuantity = $previousQuantity + $quantity;
                $subscriptionExtraLimits[$slug] = $updatedQuantity;
                $subscription->extra_limits_month = $subscriptionExtraLimits;
                $subscription->save();

                $charge = charge($total, 'RUB')->from($user)->meta([
                    'description' => "Покупка доп. лимита «{$displayName}» ({$slug}): +{$quantity} × {$unitPrice} ₽ = {$total} ₽; было {$previousQuantity}, стало {$updatedQuantity}",
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
