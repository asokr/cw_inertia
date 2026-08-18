<?php

namespace App\Http\Controllers\Web\Subscriber;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Subscriber\UpdateProfileRequest;
use App\Services\Credits\CreditBillingService;
use App\Services\Credits\CreditPriceCalculator;
use App\Services\Subscriber\ProfileService;
use App\Services\Subscriber\SubscriptionManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function show(
        Request $request,
        ProfileService $profileService,
        SubscriptionManagementService $subscriptionService,
        CreditPriceCalculator $priceCalculator,
        CreditBillingService $creditBilling,
    ): Response {
        $user = $request->user();

        return Inertia::render('Subscriber/Profile/Index', [
            'subscriptionData' => $subscriptionService->getCurrent($user),
            'rublesPerCredit' => $priceCalculator->rublesPerCredit(),
            'purchasedCredits' => $creditBilling->getBalance($user)->purchased,
        ]);
    }

    public function update(UpdateProfileRequest $request, ProfileService $profileService): RedirectResponse
    {
        $profileService->updateName($request->user(), $request->validated('name'));

        return back()->with('success', 'Данные профиля обновлены');
    }

    public function tourSeen(Request $request, ProfileService $profileService): RedirectResponse
    {
        $profileService->markTourSeen($request->user());

        return back();
    }
}