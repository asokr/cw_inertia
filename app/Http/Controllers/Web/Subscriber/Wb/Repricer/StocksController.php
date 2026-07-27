<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Repricer;

use App\Http\Controllers\Web\Subscriber\Concerns\ResolvesSelectedWbCabinet;
use App\Services\Subscriber\Wb\RepricerStocksService;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\LoadRepricerStockSizesRequest;
use App\Http\Requests\Web\Subscriber\StoreRepricerStockRequest;
use App\Http\Requests\Web\Subscriber\UpdateRepricerStockRequest;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Support\ToolLimits;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Models\Subscribers\Wb\Repricer\RepricerStocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StocksController extends SubscriberToolController
{
    use ResolvesSelectedWbCabinet;

    public function __construct(
        private readonly RepricerStocksService $stocksService,
    ) {
    }

    public function index(Request $request): Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Репрайсер цен Wildberries', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Репрайсер цен Wildberries'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

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

    public function store(StoreRepricerStockRequest $request): RedirectResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Репрайсер цен Wildberries', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Репрайсер цен Wildberries'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

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
            ->route('subscriber.wb.repricer.stocks.index')
            ->with('success', $this->apiMessage($payload, 'Номенклатура добавлена'));
    }

    public function update(
        UpdateRepricerStockRequest $request,
        RepricerStocks $stock,
    ): RedirectResponse|Response {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Репрайсер цен Wildberries', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Репрайсер цен Wildberries'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        if ((int) $stock->cabinet_id !== (int) $cabinet->id) {
            abort(404);
        }

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

    public function destroy(Request $request, RepricerStocks $stock): RedirectResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Репрайсер цен Wildberries', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Репрайсер цен Wildberries'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        if ((int) $stock->cabinet_id !== (int) $cabinet->id) {
            abort(404);
        }

        $response = $this->stocksService->destroy((string) $stock->id);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось удалить номенклатуру'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Номенклатура удалена'));
    }

    public function loadSizes(LoadRepricerStockSizesRequest $request): JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Репрайсер цен Wildberries', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Репрайсер цен Wildberries'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $response = $this->stocksService->getSizesFromWb(
            $request->duplicate(null, array_merge(
                $request->validated(),
                ['cabinet_id' => $cabinet->id]
            ))
        );
        $payload = $this->decodeApiResponse($response);

        return response()->json($payload);
    }

    public function reset(Request $request, RepricerStocks $stock): RedirectResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Репрайсер цен Wildberries', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Репрайсер цен Wildberries'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        if ((int) $stock->cabinet_id !== (int) $cabinet->id) {
            abort(404);
        }

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
