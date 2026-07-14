<?php

namespace App\Services\Subscriber;

use App\Enums\PaymentStatusEnum;
use App\Models\PaymentsTransaction;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Log;
use YooKassa\Model\Notification\NotificationCanceled;
use YooKassa\Model\Notification\NotificationEventType;
use YooKassa\Model\Notification\NotificationSucceeded;
use YooKassa\Model\Notification\NotificationWaitingForCapture;

class SubscriberPaymentService
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly SubscriptionManagementService $subscriptionService,
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

    public function handleYooKassaCallback(): void
    {
        $source = file_get_contents('php://input');
        $requestBody = json_decode($source, true);

        if (! is_array($requestBody)) {
            Log::warning('YooKassa callback: invalid JSON payload');

            return;
        }

        Log::info('YooKassa event: ' . ($requestBody['event'] ?? 'none'));

        if (($requestBody['event'] ?? null) === NotificationEventType::PAYMENT_SUCCEEDED) {
            $notification = new NotificationSucceeded($requestBody);
        } elseif (($requestBody['event'] ?? null) === NotificationEventType::PAYMENT_WAITING_FOR_CAPTURE) {
            $notification = new NotificationWaitingForCapture($requestBody);
        } else {
            $notification = new NotificationCanceled($requestBody);
        }

        $payment = $notification->getObject();

        if (isset($payment->status) && $payment->status === 'succeeded' && (bool) $payment->paid) {
            $metadata = (object) $payment->metadata;

            if (! isset($metadata->transaction_id)) {
                return;
            }

            $transactionId = (int) $metadata->transaction_id;
            $transaction = PaymentsTransaction::find($transactionId);

            if (! $transaction) {
                return;
            }

            $transaction->system_id = $payment->id;
            $transaction->status = PaymentStatusEnum::CONFIRMED;
            $transaction->save();

            $amount = (float) $payment->amount->value;
            $userId = (int) $metadata->user_id;
            $user = User::find($userId);

            if (! $user) {
                return;
            }

            deposit($amount, 'RUB')->to($user)->overcharge()
                ->meta([
                    'transaction_id' => $transactionId,
                    'description' => $transaction->description,
                ])
                ->commit();

            if ($transaction->plan_id) {
                $planResult = $this->subscriptionService->changePlan($user, (int) $transaction->plan_id);

                if (! $planResult['success']) {
                    Log::warning('Auto plan activation failed after deposit', [
                        'user_id' => $userId,
                        'plan_id' => $transaction->plan_id,
                        'message' => $planResult['messages'][0] ?? null,
                    ]);
                }
            }

            return;
        }

        if (! isset($payment->status)) {
            return;
        }

        $metadata = (object) $payment->metadata;

        if (! isset($metadata->transaction_id)) {
            return;
        }

        $transactionId = (int) $metadata->transaction_id;
        $transaction = PaymentsTransaction::find($transactionId);

        if (! $transaction) {
            return;
        }

        $transaction->system_id = $payment->id;

        if ($payment->status === 'canceled') {
            $transaction->status = PaymentStatusEnum::CANCELED;
        } else {
            $transaction->status = PaymentStatusEnum::FAILED;
        }

        $transaction->save();
    }
}