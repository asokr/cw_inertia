<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\PriceCalc;

use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresWbPriceCalcCabinetOwnership;
use App\Services\Subscriber\Wb\WbPriceCalcCabinetsService;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StoreCabinetRequest;
use App\Http\Requests\Web\Subscriber\UpdateWbPriceCalcCabinetRequest;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Support\ToolLimits;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CabinetsController extends SubscriberToolController
{
    use EnsuresWbPriceCalcCabinetOwnership;

    public function __construct(
        private readonly WbPriceCalcCabinetsService $cabinetsService,
    ) {
    }

    public function index(Request $request): Response
    {
        $response = $this->cabinetsService->index();
        $payload = $this->decodeApiResponse($response);

        $cabinets = [];
        if (($payload['success'] ?? false) === true) {
            foreach ($payload['data'] ?? [] as $cabinet) {
                $row = is_array($cabinet) ? $cabinet : $cabinet->toArray();
                $cabinets[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'created_at' => $row['created_at'] ?? null,
                    'apikey' => $row['apikey'] ?? '',
                    'href' => route('subscriber.wb.price-calc.cabinets.show', $row['id']),
                ];
            }
        }

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $request->user()->subscriber?->id)
            ->first();

        $limits = [
            'price_calc_clients' => ToolLimits::planLimitValue($request->user(), $subscription, 'price_calc_clients'),
        ];

        return Inertia::render('Subscriber/Wb/PriceCalc/Index', [
            'cabinets' => $cabinets,
            'limits' => $limits,
        ]);
    }

    public function store(StoreCabinetRequest $request): RedirectResponse
    {
        $response = $this->cabinetsService->store($request);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось добавить кабинет'));
        }

        return redirect()
            ->route('subscriber.wb.price-calc.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет добавлен'));
    }

    public function update(UpdateWbPriceCalcCabinetRequest $request, PriceCalculationCabinets $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->cabinetsService->update(
            $request->duplicate(null, [
                'name' => $request->validated('name'),
                'apikey' => $request->validated('apikey'),
            ]),
            (string) $cabinet->id
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось обновить кабинет'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Кабинет обновлён'));
    }

    public function destroy(PriceCalculationCabinets $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->cabinetsService->destroy((string) $cabinet->id);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось удалить кабинет'));
        }

        return redirect()
            ->route('subscriber.wb.price-calc.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет удалён'));
    }
}