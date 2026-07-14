<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Repricer;

use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresRepricerCabinetOwnership;
use App\Services\Subscriber\Wb\RepricerStocksService;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\LoadRepricerStockSizesRequest;
use App\Http\Requests\Web\Subscriber\StoreRepricerStockRequest;
use App\Http\Requests\Web\Subscriber\UpdateRepricerStockRequest;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Support\ToolLimits;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerStocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StocksController extends SubscriberToolController
{
    use EnsuresRepricerCabinetOwnership;

    public function __construct(
        private readonly RepricerStocksService $stocksService,
    ) {
    }

    public function index(Request $request, RepricerCabinets $cabinet): Response
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->stocksService->show((string) $cabinet->id);
        $payload = $this->decodeApiResponse($response);

        $stocks = ($payload['success'] ?? false) ? ($payload['data'] ?? []) : [];

        return Inertia::render('Subscriber/Wb/Repricer/Cabinet/Stocks/Index', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
            ],
            'stocks' => $this->normalizeRows($stocks),
            'stocksError' => ($payload['success'] ?? false) ? null : $this->apiMessage($payload, 'Не удалось загрузить номенклатуру'),
            'limits' => $this->repricerLimits($request),
        ]);
    }

    public function store(StoreRepricerStockRequest $request, RepricerCabinets $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->stocksService->store(
            $request->duplicate(null, array_merge(
                $request->validated(),
                ['cabinet_id' => $cabinet->id]
            ))
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось добавить номенклатуру'));
        }

        return redirect()
            ->route('subscriber.wb.repricer.cabinets.stocks.index', $cabinet->id)
            ->with('success', $this->apiMessage($payload, 'Номенклатура добавлена'));
    }

    public function update(
        UpdateRepricerStockRequest $request,
        RepricerCabinets $cabinet,
        RepricerStocks $stock,
    ): RedirectResponse {
        $this->ensureStockBelongsToCabinet($stock, $cabinet);

        $response = $this->stocksService->update(
            $request->duplicate(null, $request->validated()),
            (string) $stock->id
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось обновить настройки'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Настройки обновлены'));
    }

    public function destroy(RepricerCabinets $cabinet, RepricerStocks $stock): RedirectResponse
    {
        $this->ensureStockBelongsToCabinet($stock, $cabinet);

        $response = $this->stocksService->destroy((string) $stock->id);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось удалить номенклатуру'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Номенклатура удалена'));
    }

    public function loadSizes(LoadRepricerStockSizesRequest $request, RepricerCabinets $cabinet): JsonResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->stocksService->getSizesFromWb(
            $request->duplicate(null, array_merge(
                $request->validated(),
                ['cabinet_id' => $cabinet->id]
            ))
        );
        $payload = $this->decodeApiResponse($response);

        return response()->json($payload);
    }

    public function reset(RepricerCabinets $cabinet, RepricerStocks $stock): RedirectResponse
    {
        $this->ensureStockBelongsToCabinet($stock, $cabinet);

        $response = $this->stocksService->reset((string) $stock->id);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось сбросить номенклатуру'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Номенклатура сброшена'));
    }

    /**
     * @param  mixed  $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows($rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $item = is_array($row) ? $row : $row->toArray();
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @return array<string, int|null>
     */
    private function repricerLimits(Request $request): array
    {
        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $request->user()->subscriber?->id)
            ->where('status', 1)
            ->first();

        return [
            'repricer_nmid' => ToolLimits::planLimitValue($request->user(), $subscription, 'repricer_nmid'),
        ];
    }
}