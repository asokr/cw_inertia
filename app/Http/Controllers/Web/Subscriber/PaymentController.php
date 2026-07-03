<?php

namespace App\Http\Controllers\Web\Subscriber;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Subscriber\DepositRequest;
use App\Services\Subscriber\SubscriberPaymentService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PaymentController extends Controller
{
    public function index(Request $request, SubscriberPaymentService $service): Response
    {
        return Inertia::render('Subscriber/Payments/History', [
            'transactions' => $service->getHistory($request->user()),
        ]);
    }

    public function create(DepositRequest $request, SubscriberPaymentService $service): HttpResponse
    {
        $result = $service->createDeposit(
            $request->user(),
            (float) $request->validated('amount'),
        );

        if (! $result['success'] || empty($result['payment_url'])) {
            return back()->with('error', $result['messages'][0] ?? 'Не удалось создать платёж');
        }

        return Inertia::location($result['payment_url']);
    }
}