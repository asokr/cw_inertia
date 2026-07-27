<?php

namespace App\Http\Controllers\Web\Subscriber;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Subscriber\BuyExtraLimitRequest;
use App\Services\Subscriber\ExtraLimitPurchaseService;
use Illuminate\Http\RedirectResponse;

class ExtraLimitController extends Controller
{
    public function purchase(
        BuyExtraLimitRequest $request,
        ExtraLimitPurchaseService $service,
    ): RedirectResponse {
        $validated = $request->validated();

        $result = $service->purchase(
            $request->user(),
            (int) $validated['id'],
            (int) $validated['quantity'],
        );

        if (! $result['success']) {
            return back()->with('error', $result['messages'][0] ?? 'Ошибка покупки');
        }

        return back()->with('success', $result['messages'][0]);
    }
}
