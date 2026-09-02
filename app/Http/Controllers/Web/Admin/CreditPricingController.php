<?php

namespace App\Http\Controllers\Web\Admin;

use App\Exceptions\Credits\InvalidCreditOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiCabinetAnalyzerCreditTariffRequest;
use App\Http\Requests\Admin\StoreCreditServiceTierRequest;
use App\Http\Requests\Admin\UpdateAiCabinetAnalyzerCreditTariffRequest;
use App\Http\Requests\Admin\UpdateCreditRublesRequest;
use App\Http\Requests\Admin\UpdateCreditServiceRequest;
use App\Http\Requests\Admin\UpdateCreditServiceTierRequest;
use App\Models\Credits\AiCabinetAnalyzerCreditTariff;
use App\Models\Credits\CreditService;
use App\Models\Credits\CreditServicePriceTier;
use App\Services\Admin\AdminCreditPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CreditPricingController extends Controller
{
    public function __construct(
        private readonly AdminCreditPricingService $pricingService,
    ) {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Admin/CreditPricing/Index', $this->pricingService->pageData($request));
    }

    public function updateRubles(UpdateCreditRublesRequest $request): RedirectResponse
    {
        try {
            $this->pricingService->updateRublesPerCredit($request->validated('rubles_per_credit'));
        } catch (InvalidCreditOperationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Стоимость одного кредита сохранена');
    }

    public function updateService(UpdateCreditServiceRequest $request, CreditService $creditService): RedirectResponse
    {
        try {
            $this->pricingService->updateFixedAmount($creditService, (int) $request->validated('amount'));
        } catch (InvalidCreditOperationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Стоимость услуги сохранена');
    }

    public function storeTier(StoreCreditServiceTierRequest $request, CreditService $creditService): RedirectResponse
    {
        try {
            $this->pricingService->addResolution($creditService, $request->validated());
        } catch (InvalidCreditOperationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Разрешение добавлено');
    }

    public function updateTier(UpdateCreditServiceTierRequest $request, CreditServicePriceTier $tier): RedirectResponse
    {
        try {
            $this->pricingService->updateTier($tier, $request->validated());
        } catch (InvalidCreditOperationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Стоимость разрешения сохранена');
    }

    public function destroyTier(CreditServicePriceTier $tier): RedirectResponse
    {
        $this->pricingService->deleteTier($tier);

        return back()->with('success', 'Разрешение удалено');
    }

    public function storeCabinetAnalyzerTariff(StoreAiCabinetAnalyzerCreditTariffRequest $request): RedirectResponse
    {
        try {
            $this->pricingService->createCabinetAnalyzerTariff($request->validated());
        } catch (InvalidCreditOperationException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            throw $exception;
        }

        return back()->with('success', 'Ставка ИИ-анализа сохранена');
    }

    public function updateCabinetAnalyzerTariff(
        UpdateAiCabinetAnalyzerCreditTariffRequest $request,
        AiCabinetAnalyzerCreditTariff $tariff,
    ): RedirectResponse {
        try {
            $this->pricingService->updateCabinetAnalyzerTariff($tariff, $request->validated());
        } catch (InvalidCreditOperationException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            throw $exception;
        }

        return back()->with('success', 'Ставка ИИ-анализа сохранена');
    }

    public function destroyCabinetAnalyzerTariff(AiCabinetAnalyzerCreditTariff $tariff): RedirectResponse
    {
        try {
            $this->pricingService->deleteCabinetAnalyzerTariff($tariff);
        } catch (InvalidCreditOperationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Ставка ИИ-анализа удалена');
    }
}
