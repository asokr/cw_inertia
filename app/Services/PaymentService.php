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

    public function createPayment(float $amount, string $description, array $options = [], ?string $returnUrl = null)
    {
        $client = $this->getClient();

        $metadata = [
            'transaction_id' => $options['transaction_id'] ?? null,
            'user_id' => $options['user_id'] ?? auth()->id(),
        ];

        if (! empty($options['plan_id'])) {
            $metadata['plan_id'] = $options['plan_id'];
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

        return $payment->getConfirmation()->getConfirmationUrl();
    }
}