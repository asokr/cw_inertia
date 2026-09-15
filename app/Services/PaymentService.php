<?php

namespace App\Services;

use YooKassa\Client;

class PaymentService
{
    public function getClient()
    {
        $client = new Client();
        $client->setAuth(config('services.yookassa.shop_id'), config('services.yookassa.secret_key'));

        return $client;
    }

    /**
     * @return array{url: string, id: string}
     */
    public function createPayment(float $amount, string $description, array $options = [], ?string $returnUrl = null): array
    {
        $client = $this->getClient();

        $metadata = [];

        if (isset($options['transaction_id']) && $options['transaction_id'] !== null && $options['transaction_id'] !== '') {
            $metadata['transaction_id'] = (string) $options['transaction_id'];
        }

        $userId = $options['user_id'] ?? auth()->id();
        if ($userId !== null && $userId !== '') {
            $metadata['user_id'] = (string) $userId;
        }

        if (! empty($options['plan_id'])) {
            $metadata['plan_id'] = (string) $options['plan_id'];
        }

        $payment = $client->createPayment(
            [
                'amount' => [
                    'value' => $amount,
                    'currency' => 'RUB',
                ],
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => $returnUrl ?? url('/panel'),
                ],
                'capture' => true,
                'description' => $description,
                'metadata' => $metadata,
                'receipt' => [
                    'customer' => [
                        'email' => auth()->user()->email,
                    ],
                    'items' => [
                        [
                            'description' => 'Пополнение счета CWPlatform',
                            'quantity' => 1.000,
                            'amount' => [
                                'value' => $amount,
                                'currency' => 'RUB',
                            ],
                            'tax_system_code' => 2,
                            'vat_code' => 1,
                            'payment_mode' => 'full_payment',
                            'payment_subject' => 'service',
                        ],
                    ],
                ],
            ],
            uniqid('', true)
        );

        return [
            'url' => $payment->getConfirmation()->getConfirmationUrl(),
            'id' => (string) $payment->getId(),
        ];
    }
}
