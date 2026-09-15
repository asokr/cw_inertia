<?php

namespace App\Services\Subscriber;

use App\Enums\PaymentStatusEnum;
use App\Models\PaymentsTransaction;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use YooKassa\Model\Notification\NotificationEventType;

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

        $payment = $this->paymentService->createPayment($amount, $description, [
            'transaction_id' => $transaction->id,
            'user_id' => $user->id,
            'plan_id' => $planId,
        ], $returnUrl);

        $transaction->system_id = $payment['id'];
        $transaction->save();

        return [
            'success' => true,
            'messages' => ['Платёж создан'],
            'payment_url' => $payment['url'],
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

    public function handleYooKassaCallback(Request $request): void
    {
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return;
        }

        $event = $payload['event'] ?? null;
        $object = $payload['object'] ?? null;

        if (! is_array($object)) {
            return;
        }

        if ($event === NotificationEventType::PAYMENT_SUCCEEDED) {
            $this->confirmYooPayment($object);

            return;
        }

        if ($event === NotificationEventType::PAYMENT_CANCELED) {
            $this->cancelYooPayment($object);
        }
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function confirmYooPayment(array $object): void
    {
        $paid = filter_var($object['paid'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! $paid) {
            return;
        }

        $transaction = $this->findTransaction($object);
        if (! $transaction) {
            return;
        }

        DB::transaction(function () use ($transaction, $object) {
            /** @var PaymentsTransaction|null $locked */
            $locked = PaymentsTransaction::query()
                ->lockForUpdate()
                ->find($transaction->id);

            if (! $locked || $locked->status === PaymentStatusEnum::CONFIRMED) {
                return;
            }

            $user = User::query()->find((int) $locked->user_id);
            if (! $user) {
                return;
            }

            $amount = (float) ($object['amount']['value'] ?? $locked->amount);
            $paymentId = (string) ($object['id'] ?? '');
            $walletBalance = $user->balance('RUB');
            $balanceBefore = $this->numericToFloat($walletBalance->value);

            $walletTx = deposit($amount, 'RUB')->to($user)->overcharge()
                ->meta([
                    'transaction_id' => $locked->id,
                    'description' => $locked->description,
                ])
                ->commit();

            if ($walletTx === false) {
                throw new \RuntimeException('Не удалось зачислить баланс');
            }

            if ($paymentId !== '') {
                $locked->system_id = $paymentId;
            }
            $locked->status = PaymentStatusEnum::CONFIRMED;
            $locked->save();

            $walletBalance->refresh();
            $balanceAfter = $this->numericToFloat($walletBalance->value);

            // Хронология пополнения: файл storage/logs/balance-YYYY-MM-DD.log
            Log::channel('balance')->info('YooKassa deposit completed', [
                'user_id' => $user->id,
                'transaction_id' => $locked->id,
                'wallet_transaction_id' => is_object($walletTx) && method_exists($walletTx, 'getKey')
                    ? $walletTx->getKey()
                    : null,
                'payment_id' => $paymentId !== '' ? $paymentId : $locked->system_id,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'plan_id' => $locked->plan_id,
                'description' => $locked->description,
            ]);

            if ($locked->plan_id) {
                $this->subscriptionService->changePlan($user, (int) $locked->plan_id);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function cancelYooPayment(array $object): void
    {
        $transaction = $this->findTransaction($object);
        if (! $transaction) {
            return;
        }

        if ($transaction->status === PaymentStatusEnum::CONFIRMED) {
            return;
        }

        $paymentId = (string) ($object['id'] ?? '');
        if ($paymentId !== '') {
            $transaction->system_id = $paymentId;
        }
        $transaction->status = PaymentStatusEnum::CANCELED;
        $transaction->save();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function findTransaction(array $object): ?PaymentsTransaction
    {
        $metadata = $object['metadata'] ?? [];
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $transactionId = $metadata['transaction_id'] ?? null;
        if ($transactionId !== null && $transactionId !== '') {
            $transaction = PaymentsTransaction::query()->find((int) $transactionId);
            if ($transaction) {
                return $transaction;
            }
        }

        $paymentId = $object['id'] ?? null;
        if (is_string($paymentId) && $paymentId !== '') {
            return PaymentsTransaction::query()->where('system_id', $paymentId)->first();
        }

        return null;
    }

    private function numericToFloat(mixed $value): float
    {
        if (is_object($value) && method_exists($value, 'get')) {
            return (float) $value->get();
        }

        return (float) (string) $value;
    }
}
