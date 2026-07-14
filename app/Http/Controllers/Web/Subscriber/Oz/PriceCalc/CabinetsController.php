<?php

namespace App\Http\Controllers\Web\Subscriber\Oz\PriceCalc;

use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresOzPriceCalcCabinetOwnership;
use App\Services\Subscriber\Oz\OzPriceCalcCabinetsService;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StoreOzPriceCalcCabinetRequest;
use App\Http\Requests\Web\Subscriber\UpdateOzPriceCalcCabinetRequest;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcCabinet;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Support\ToolLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CabinetsController extends SubscriberToolController
{
    use EnsuresOzPriceCalcCabinetOwnership;

    public function __construct(
        private readonly OzPriceCalcCabinetsService $cabinetsService,
    ) {
    }

    public function index(Request $request): Response
    {
        $response = $this->cabinetsService->index($request);
        $payload = $this->decodeApiResponse($response);

        $cabinets = [];
        if (($payload['success'] ?? false) === true) {
            foreach ($payload['data'] ?? [] as $cabinet) {
                $row = is_array($cabinet) ? $cabinet : $cabinet->toArray();
                $cabinets[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'client_id' => $row['client_id'] ?? '',
                    'created_at' => $row['created_at'] ?? null,
                    'apikey' => $row['apikey'] ?? '',
                    'href' => route('subscriber.oz.price-calc.cabinets.show', $row['id']),
                ];
            }
        }

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $request->user()->subscriber?->id)
            ->first();

        $limits = [
            'oz_price_calc_clients' => ToolLimits::planLimitValue($request->user(), $subscription, 'oz_price_calc_clients'),
        ];

        return Inertia::render('Subscriber/Oz/PriceCalc/Index', [
            'cabinets' => $cabinets,
            'limits' => $limits,
        ]);
    }

    public function store(StoreOzPriceCalcCabinetRequest $request): RedirectResponse
    {
        $response = $this->cabinetsService->store($request);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось добавить кабинет'));
        }

        return redirect()
            ->route('subscriber.oz.price-calc.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет добавлен'));
    }

    public function update(UpdateOzPriceCalcCabinetRequest $request, OzPriceCalcCabinet $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->cabinetsService->update(
            $request->duplicate(null, $request->validated()),
            (int) $cabinet->id
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось обновить кабинет'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Кабинет обновлён'));
    }

    public function destroy(OzPriceCalcCabinet $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->cabinetsService->destroy(request(), (int) $cabinet->id);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось удалить кабинет'));
        }

        return redirect()
            ->route('subscriber.oz.price-calc.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет удалён'));
    }
}