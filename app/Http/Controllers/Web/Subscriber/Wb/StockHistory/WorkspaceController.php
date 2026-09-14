<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\StockHistory;

use App\Http\Controllers\Web\Subscriber\Concerns\ResolvesSelectedWbCabinet;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\LoadWbStockHistoryRequest;
use App\Http\Requests\Web\Subscriber\UpdateWbStockHistorySettingsRequest;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Subscriber\Wb\WbStockHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends SubscriberToolController
{
    use ResolvesSelectedWbCabinet;

    private const TOOL_NAME = 'История остатков и заказов';

    public function __construct(
        private readonly WbStockHistoryService $stockHistoryService,
    ) {}

    public function show(Request $request): Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, self::TOOL_NAME, $this->breadcrumbs());
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $tab = $this->tab($request);
        $settings = $this->stockHistoryService->settingsFor((int) $cabinet->id);
        $tracking = $this->stockHistoryService->trackingPayload($settings);
        $period = $this->stockHistoryService->resolvePeriod($request);

        $list = [
            'items' => [],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => WbStockHistoryService::PER_PAGE,
                'total' => 0,
            ],
            'loaded_dates' => [],
        ];

        if ($tab === 'orders' && ($tracking['orders']['has_history'] ?? false)) {
            $list = $this->stockHistoryService->listOrders((int) $cabinet->id, $request, $period);
        } elseif ($tab === 'stocks' && ($tracking['stocks']['has_history'] ?? false)) {
            $list = $this->stockHistoryService->listStocks($cabinet, $request, $period);
        }

        return Inertia::render('Subscriber/Wb/StockHistory/Index', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
            ],
            'tab' => $tab,
            'tracking' => $tracking,
            'filters' => [
                'tab' => $tab,
                'from' => $period['from'],
                'to' => $period['to'],
                'page' => (int) $request->input('page', 1),
                'subject' => (string) $request->input('subject', ''),
                'vendor_code' => (string) $request->input('vendor_code', $request->input('search', '')),
                'nm_id' => (string) $request->input('nm_id', ''),
                'tech_size' => (string) $request->input('tech_size', ''),
                'barcode' => (string) $request->input('barcode', ''),
            ],
            'dates' => $period['dates'],
            'loadedDates' => $list['loaded_dates'],
            'rows' => $list['items'],
            'rowsMeta' => $list['meta'],
        ]);
    }

    public function status(Request $request): JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if (! $cabinetOrResponse instanceof WbCabinet) {
            return $cabinetOrResponse;
        }

        $settings = $this->stockHistoryService->settingsFor((int) $cabinetOrResponse->id);

        return response()->json([
            'success' => true,
            'messages' => [],
            'data' => $this->stockHistoryService->trackingPayload($settings),
        ]);
    }

    public function stockWarehouses(Request $request, int $nmId, int $chrtId): JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if (! $cabinetOrResponse instanceof WbCabinet) {
            return $cabinetOrResponse;
        }

        $period = $this->stockHistoryService->resolvePeriod($request);
        $detail = $this->stockHistoryService->listStockWarehouses($cabinetOrResponse, $nmId, $chrtId, $period);
        if ($detail === null) {
            return response()->json([
                'success' => false,
                'messages' => ['Товар не найден.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'messages' => [],
            'data' => $detail,
        ]);
    }

    public function loadStocks(Request $request): RedirectResponse|JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, self::TOOL_NAME, $this->breadcrumbs());
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $result = $this->stockHistoryService->queueStockLoad($cabinet);

        return $this->toolResponse($request, $result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function loadOrders(Request $request): RedirectResponse|JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, self::TOOL_NAME, $this->breadcrumbs());
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $result = $this->stockHistoryService->queueOrderLoad($cabinet);

        return $this->toolResponse($request, $result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function refreshStocks(LoadWbStockHistoryRequest $request): RedirectResponse|JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, self::TOOL_NAME, $this->breadcrumbs());
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $result = $this->stockHistoryService->queueStockRefresh(
            $cabinet,
            (string) $request->validated('from'),
        );

        return $this->toolResponse($request, $result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function startStocks(Request $request): RedirectResponse|JsonResponse|Response
    {
        return $this->runCabinetAction(
            $request,
            fn (WbCabinet $cabinet): array => $this->stockHistoryService->startStocksTracking((int) $cabinet->id),
        );
    }

    public function stopStocks(Request $request): RedirectResponse|JsonResponse|Response
    {
        return $this->runCabinetAction(
            $request,
            fn (WbCabinet $cabinet): array => $this->stockHistoryService->stopStocksTracking((int) $cabinet->id),
        );
    }

    public function startOrders(Request $request): RedirectResponse|JsonResponse|Response
    {
        return $this->runCabinetAction(
            $request,
            fn (WbCabinet $cabinet): array => $this->stockHistoryService->startOrdersTracking((int) $cabinet->id),
        );
    }

    public function stopOrders(Request $request): RedirectResponse|JsonResponse|Response
    {
        return $this->runCabinetAction(
            $request,
            fn (WbCabinet $cabinet): array => $this->stockHistoryService->stopOrdersTracking((int) $cabinet->id),
        );
    }

    public function updateSettings(UpdateWbStockHistorySettingsRequest $request): RedirectResponse|JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, self::TOOL_NAME, $this->breadcrumbs());
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $result = $this->stockHistoryService->updateRetention(
            (int) $cabinet->id,
            (int) $request->validated('retention_days'),
        );

        return $this->toolResponse($request, $result);
    }

    /**
     * @param  callable(WbCabinet): array{success?: bool, messages?: list<string>}  $callback
     */
    private function runCabinetAction(Request $request, callable $callback): RedirectResponse|JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, self::TOOL_NAME, $this->breadcrumbs());
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $result = $callback($cabinet);

        return $this->toolResponse($request, $result, ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * @param  array{success?: bool, messages?: list<string>}  $result
     */
    private function toolResponse(Request $request, array $result, int $errorStatus = 422): RedirectResponse|JsonResponse
    {
        $success = (bool) ($result['success'] ?? false);
        $messages = $result['messages'] ?? [];

        if ($request->wantsJson()) {
            return response()->json([
                'success' => $success,
                'messages' => $messages,
                'data' => [],
            ], $success ? 200 : $errorStatus);
        }

        return back()->with($success ? 'success' : 'error', implode(' ', $messages));
    }

    private function tab(Request $request): string
    {
        return $request->input('tab') === 'orders' ? 'orders' : 'stocks';
    }

    /**
     * @return list<array{label: string, href?: string}>
     */
    private function breadcrumbs(): array
    {
        return [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => self::TOOL_NAME],
        ];
    }
}
