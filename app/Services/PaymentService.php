<?php

namespace App\Services;

use YooKassa\Client;

class PaymentService {

    public function getClient()
    {
        $client = new Client();
        $client->setAuth(config('services.yookassa.shop_id'), config('services.yookassa.secret_key'));

        return $client;
    }

    public function createPayment(float $amount, string $description, array $options = [])
    {
        $client = $this->getClient();

        $payment = $client->createPayment(
            array(
                'amount' => array(
                    'value' => $amount,
                    'currency' => 'RUB',
                ),
                'confirmation' => array(
                    'type' => 'redirect',
                    'return_url' => 'https://cwplatform.ru/panel',
                ),
                'capture' => true,
                'description' => $description,
                'metadata' => [
                    'transaction_id' => $options['transaction_id'],
                    'user_id' => auth()->id()
                ],
                'receipt' => array(
                    'customer' => array(
                        'email' => auth()->user()->email,
                    ),
                    'items' => array(
                        array(
                            'description' => 'Пополнение счета CWPlatform',
                            'quantity' => 1.000,
                            'amount' => array(
                                'value' => $amount,
                                'currency' => 'RUB'
                            ),
                            'tax_system_code' => 2,
                            'vat_code' => 1,
                            'payment_mode' => 'full_payment',
                            'payment_subject' => 'service',
                        )
                    )
                )
            ),
            uniqid('', true)
        );

        return $payment->getConfirmation()->getConfirmationUrl();

    }

}
