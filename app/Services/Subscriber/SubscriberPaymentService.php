<?php

namespace App\Services\Subscriber;

use App\Models\PaymentsTransaction;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\User;
use App\Services\PaymentService;

class SubscriberPaymentService
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {
    }

    /**
     * @return array{success: bool, messages: array<int, string>, payment_url?: string}
     */
    public function createDeposit(User $user, float $amount, ?int $planId = null): array
    {
        $description = 'Пополнение баланса';

        if ($planId) {
            $plan = SubscribersPlans::query()->select(['name'])->find($planId);
            if ($plan) {
                $description = "Пополнение для тарифа «{$plan->name}»";
            }
        }

        $transaction = PaymentsTransaction::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'plan_id' => $planId,
            'description' => $description,
            'system' => 'YooKassa',
        ]);

        if (! $transaction) {
            return ['success' => false, 'messages' => ['Не удалось создать платёж']];
        }

        $returnUrl = $planId
            ? url('/panel/plans?payment=success')
            : url('/panel');

        $link = $this->paymentService->createPayment($amount, $description, [
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'plan_id' => $planId,
        ], $returnUrl);

        return [
            'success' => true,
            'messages' => ['Платёж создан'],
            'payment_url' => $link,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(User $user): array
    {
        return PaymentsTransaction::select([
            'id',
            'amount',
            'description',
            'status',
            'system',
            'created_at',
        ])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get()
            ->toArray();
    }
}