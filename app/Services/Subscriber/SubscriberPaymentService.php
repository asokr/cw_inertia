<?php

namespace App\Services\Subscriber;

use App\Models\PaymentsTransaction;
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
    public function createDeposit(User $user, float $amount): array
    {
        $description = 'Пополнение баланса';

        $transaction = PaymentsTransaction::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'description' => $description,
            'system' => 'YooKassa',
        ]);

        if (! $transaction) {
            return ['success' => false, 'messages' => ['Не удалось создать платёж']];
        }

        $link = $this->paymentService->createPayment($amount, $description, [
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
        ]);

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