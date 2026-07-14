<?php

namespace App\Http\Controllers\Web\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Subscriber\SubscriberPaymentService;
use Illuminate\Http\Response;

class YooKassaWebhookController extends Controller
{
    public function __invoke(SubscriberPaymentService $paymentService): Response
    {
        $paymentService->handleYooKassaCallback();

        return response()->noContent();
    }
}