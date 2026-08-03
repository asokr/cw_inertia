<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\PromoCalculator;

use App\Http\Controllers\Web\Subscriber\Concerns\ResolvesSelectedWbCabinet;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\CalculatePromoCalculatorRequest;
use App\Http\Requests\Web\Subscriber\ExportPromoCalculatorRequest;
use App\Http\Requests\Web\Subscriber\SendPromoToRepricerRequest;
use App\Http\Requests\Web\Subscriber\UploadPromoCalculatorFileRequest;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Subscriber\Wb\WbPromoCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PromoCalculatorController extends SubscriberToolController
{
    use ResolvesSelectedWbCabinet;

    public function __construct(
        private readonly WbPromoCalculatorService $promoCalculatorService,
    ) {
    }

    public function index(Request $request): Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Рентабельность акций', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Рентабельность акций'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        return Inertia::render('Subscriber/Wb/PromoCalculator/Index', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
            ],
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
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $response = $this->promoCalculatorService->calculate($request, (int) $cabinet->id);

        return response()->json($this->decodeApiResponse($response));
    }

    public function export(ExportPromoCalculatorRequest $request): JsonResponse
    {
        $response = $this->promoCalculatorService->getPromoXlsx($request);

        return response()->json($this->decodeApiResponse($response));
    }

    public function sendToRepricer(SendPromoToRepricerRequest $request): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $response = $this->promoCalculatorService->sendToRepricer($request, $cabinet);

        return response()->json($this->decodeApiResponse($response));
    }
}
