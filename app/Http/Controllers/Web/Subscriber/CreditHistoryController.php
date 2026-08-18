<?php

namespace App\Http\Controllers\Web\Subscriber;

use App\Http\Controllers\Controller;
use App\Services\Credits\CreditBillingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditHistoryController extends Controller
{
    public function index(Request $request, CreditBillingService $billing): Response
    {
        return Inertia::render('Subscriber/Credits/History', [
            'entries' => $billing->historyForFrontend($request->user(), 100),
            'credits' => $billing->getBalance($request->user())->toFrontendArray(),
        ]);
    }
}
