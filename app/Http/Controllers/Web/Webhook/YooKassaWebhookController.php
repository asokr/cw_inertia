<?php

namespace App\Http\Controllers\Web\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Subscriber\SubscriberPaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class YooKassaWebhookController extends Controller
{
    public function __invoke(Request $request, SubscriberPaymentService $paymentService): Response
    {
        $paymentService->handleYooKassaCallback($request);

        return response()->noContent();
    }
}
