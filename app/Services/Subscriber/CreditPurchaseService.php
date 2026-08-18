<?php

namespace App\Services\Subscriber;

use App\Enums\Credits\CreditLedgerType;
use App\Exceptions\Credits\InvalidCreditOperationException;
use App\Services\Credits\CreditBillingService;
use App\Services\Credits\CreditPriceCalculator;
use App\Support\CreditsFormatter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreditPurchaseService
{
    public function __construct(
        private readonly CreditBillingService $billing,
        private readonly CreditPriceCalculator $calculator,
    ) {
    }

    /**
     * @return array{success: bool, messages: array<int, string>, data?: array<string, mixed>}
     */
    public function purchase(User $user, int $quantity): array
    {
        if ($quantity < 1) {
            return ['success' => false, 'messages' => ['Укажите количество']];
        }

        if (! $this->billing->isReady() || ! $this->calculator->isReady()) {
            return ['success' => false, 'messages' => ['Покупка кредитов временно недоступна']];
        }

        if (! $user->getSubscriptions()) {
            return ['success' => false, 'messages' => ['У вас нет активной подписки']];
        }

        try {
            $unitPrice = $this->calculator->rublesPerCredit();
            $total = $this->calculator->purchaseCost($quantity);
        } catch (InvalidCreditOperationException $exception) {
            return ['success' => false, 'messages' => [$exception->getMessage()]];
        }

        $logContext = [
            'user_id' => $user->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $total,
            'currency' => 'RUB',
        ];

        if (! $user->isEnoughFunds((float) $total, 'RUB')) {
            Log::channel('balance')->warning('Credit purchase aborted: insufficient funds', $logContext);

            return ['success' => false, 'messages' => ['Недостаточно средств']];
        }

        Log::channel('balance')->info('Credit purchase initiated', $logContext);

        try {
            DB::transaction(function () use ($user, $quantity, $total, $unitPrice) {
                $charge = charge((float) $total, 'RUB')->from($user)->meta([
                    'description' => sprintf(
                        'Покупка %s: %s × %s ₽ = %s ₽',
                        CreditsFormatter::amount($quantity),
                        $quantity,
                        $unitPrice,
                        $total
                    ),
                    'operation' => 'credit_purchase',
                    'credits' => $quantity,
                    'rubles_per_credit' => $unitPrice,
                ])->commit();

                if ($charge === false) {
                    throw new \RuntimeException('Не удалось списать средства.');
                }

                $this->billing->addPurchased($user, $quantity, [
                    'type' => CreditLedgerType::Purchase,
                    'description' => sprintf('Покупка %s за %s ₽', CreditsFormatter::amount($quantity), $total),
                    'user_label' => 'Покупка '.CreditsFormatter::amount($quantity),
                    'related_type' => is_object($charge) ? $charge::class : null,
                    'related_id' => is_object($charge) ? ($charge->uuid ?? $charge->id ?? null) : null,
                ]);
            });
        } catch (\Throwable $exception) {
            Log::channel('balance')->error('Credit purchase failed', array_merge($logContext, [
                'exception' => $exception->getMessage(),
            ]));
            report($exception);

            return ['success' => false, 'messages' => ['Не удалось оформить покупку. Попробуйте ещё раз']];
        }

        $balance = $this->billing->getBalance($user);

        return [
            'success' => true,
            'messages' => ['Кредиты зачислены'],
            'data' => [
                'purchased' => $balance->purchased,
                'available' => $balance->available(),
            ],
        ];
    }
}
