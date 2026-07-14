<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\PromoCalculator;

use App\Services\Subscriber\Wb\WbPriceCalcCabinetsService;
use App\Services\Subscriber\Wb\WbPromoCalculatorService;
use App\Services\Subscriber\Wb\RepricerCabinetsService;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\CalculatePromoCalculatorRequest;
use App\Http\Requests\Web\Subscriber\ExportPromoCalculatorRequest;
use App\Http\Requests\Web\Subscriber\SendPromoToRepricerRequest;
use App\Http\Requests\Web\Subscriber\UploadPromoCalculatorFileRequest;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PromoCalculatorController extends SubscriberToolController
{
    public function __construct(
        private readonly WbPromoCalculatorService $promoCalculatorService,
        private readonly WbPriceCalcCabinetsService $priceCalcCabinetsService,
        private readonly RepricerCabinetsService $repricerCabinetsService,
    ) {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Subscriber/Wb/PromoCalculator/Index', [
            'priceCalcCabinets' => $this->loadPriceCalcCabinets($request),
            'repricerCabinets' => $this->loadRepricerCabinets($request),
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
        $cabinet = PriceCalculationCabinets::query()->findOrFail($request->integer('cabinet_id'));
        $this->ensurePriceCalcCabinetOwnership($cabinet);

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
        $cabinet = RepricerCabinets::query()->findOrFail($request->integer('cabinet_id'));
        $this->ensureRepricerCabinetOwnership($cabinet);

        $response = $this->promoCalculatorService->sendToRepricer($request);

        return response()->json($this->decodeApiResponse($response));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadPriceCalcCabinets(Request $request): array
    {
        if (! $request->user()?->can('subscriber wb price calculator')) {
            return [];
        }

        $response = $this->priceCalcCabinetsService->index();
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return [];
        }

        return array_values(array_map(static function ($cabinet) {
            $row = is_array($cabinet) ? $cabinet : $cabinet->toArray();

            return [
                'id' => $row['id'],
                'name' => $row['name'],
            ];
        }, $payload['data'] ?? []));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRepricerCabinets(Request $request): array
    {
        if (! $request->user()?->can('subscriber wb repricer')) {
            return [];
        }

        $response = $this->repricerCabinetsService->index();
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return [];
        }

        return array_values(array_map(static function ($cabinet) {
            $row = is_array($cabinet) ? $cabinet : $cabinet->toArray();

            return [
                'id' => $row['id'],
                'name' => $row['name'],
            ];
        }, $payload['data'] ?? []));
    }

    private function ensurePriceCalcCabinetOwnership(PriceCalculationCabinets $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }

    private function ensureRepricerCabinetOwnership(RepricerCabinets $cabinet): void
    {
        if ((int) $cabinet->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }
}