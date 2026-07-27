<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\PromoCalculator;

use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\CalculatePromoCalculatorRequest;
use App\Http\Requests\Web\Subscriber\ExportPromoCalculatorRequest;
use App\Http\Requests\Web\Subscriber\SendPromoToRepricerRequest;
use App\Http\Requests\Web\Subscriber\UploadPromoCalculatorFileRequest;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Subscriber\Wb\WbCabinetService;
use App\Services\Subscriber\Wb\WbPromoCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PromoCalculatorController extends SubscriberToolController
{
    public function __construct(
        private readonly WbPromoCalculatorService $promoCalculatorService,
        private readonly WbCabinetService $wbCabinets,
    ) {
    }

    public function index(Request $request): Response
    {
        $cabinets = $this->loadCabinets($request);

        return Inertia::render('Subscriber/Wb/PromoCalculator/Index', [
            'priceCalcCabinets' => $cabinets,
            'repricerCabinets' => $cabinets,
            'canUseRepricer' => $request->user()?->can('subscriber wb repricer') ?? false,
        ]);
    }

    public function upload(UploadPromoCalculatorFileRequest $request): JsonResponse
    {
        $response = $this->promoCalculatorService->upload($request);

        return response()->json($this->decodeApiResponse($response));
    }

    public function calculate(CalculatePromoCalculatorRequest $request): JsonResponse
    {
        $cabinet = WbCabinet::query()->findOrFail($request->integer('cabinet_id'));
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->promoCalculatorService->calculate($request);

        return response()->json($this->decodeApiResponse($response));
    }

    public function export(ExportPromoCalculatorRequest $request): JsonResponse
    {
        $response = $this->promoCalculatorService->getPromoXlsx($request);

        return response()->json($this->decodeApiResponse($response));
    }

    public function sendToRepricer(SendPromoToRepricerRequest $request): JsonResponse
    {
        $cabinet = WbCabinet::query()->findOrFail($request->integer('cabinet_id'));
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->promoCalculatorService->sendToRepricer($request);

        return response()->json($this->decodeApiResponse($response));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadCabinets(Request $request): array
    {
        if (! $request->user()) {
            return [];
        }

        return $this->wbCabinets->listSummaries($request->user());
    }

    private function ensureCabinetOwnership(WbCabinet $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }
}
