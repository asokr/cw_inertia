<?php

namespace App\Http\Controllers\Web\Subscriber;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Subscriber\ChangePlanRequest;
use App\Services\Subscriber\SubscriptionManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function changePlan(
        ChangePlanRequest $request,
        SubscriptionManagementService $service,
    ): RedirectResponse {
        $result = $service->changePlan(
            $request->user(),
            (int) $request->validated('plan_id'),
        );

        if (! $result['success']) {
            return back()
                ->with('error', $result['messages'][0] ?? 'Ошибка смены тарифа')
                ->with('error_details', isset($result['limit_violations'])
                    ? ['limit_violations' => $result['limit_violations']]
                    : null);
        }

        return back()
            ->with('success', $result['messages'][0] ?? 'Тариф изменён')
            ->with('success_details', $result['success_details'] ?? null);
    }

    public function unsubscribe(Request $request, SubscriptionManagementService $service): RedirectResponse
    {
        $request->validate([
            'id' => ['required', 'exists:subscribers_subscriptions,id'],
        ]);

        $result = $service->unsubscribe($request->user(), (int) $request->input('id'));

        if (! $result['success']) {
            return back()->with('error', $result['messages'][0] ?? 'Ошибка');
        }

        return back()->with('success', $result['messages'][0]);
    }

    public function resubscribe(Request $request, SubscriptionManagementService $service): RedirectResponse
    {
        $request->validate([
            'id' => ['required', 'exists:subscribers_subscriptions,id'],
        ]);

        $result = $service->resubscribe($request->user(), (int) $request->input('id'));

        if (! $result['success']) {
            return back()->with('error', $result['messages'][0] ?? 'Ошибка');
        }

        return back()->with('success', $result['messages'][0]);
    }

    public function cancelDowngrade(SubscriptionManagementService $service): RedirectResponse
    {
        $result = $service->cancelScheduledDowngrade(request()->user());

        if (! $result['success']) {
            return back()->with('error', $result['messages'][0] ?? 'Ошибка');
        }

        return back()->with('success', $result['messages'][0]);
    }
}