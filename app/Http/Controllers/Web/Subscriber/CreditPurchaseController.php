<?php

namespace App\Http\Controllers\Web\Subscriber;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Subscriber\BuyCreditsRequest;
use App\Services\Subscriber\CreditPurchaseService;
use Illuminate\Http\RedirectResponse;

class CreditPurchaseController extends Controller
{
    public function purchase(
        BuyCreditsRequest $request,
        CreditPurchaseService $service,
    ): RedirectResponse {
        $result = $service->purchase(
            $request->user(),
            (int) $request->validated('quantity'),
        );

        if (! $result['success']) {
            return back()->with('error', $result['messages'][0] ?? 'Ошибка покупки');
        }

        return back()->with('success', $result['messages'][0]);
    }
}
