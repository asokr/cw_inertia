<?php

namespace App\Http\Controllers\Web\Subscriber\Oz\StockHistory;

use App\Http\Controllers\Web\Subscriber\Concerns\ResolvesSelectedOzCabinet;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\UpdateOzStockHistorySettingsRequest;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Services\Subscriber\Oz\OzStockHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends SubscriberToolController
{
    use ResolvesSelectedOzCabinet;

    private const TOOL_NAME = 'История остатков';

    public function __construct(
        private readonly OzStockHistoryService $stockHistoryService,
    ) {}

    public function show(Request $request): Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinet($request, self::TOOL_NAME, $this->breadcrumbs());
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var OzCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $settings = $this->stockHistoryService->settingsFor((int) $cabinet->id);
        $tracking = $this->stockHistoryService->trackingPayload($settings);
        $period = $this->stockHistoryService->resolvePeriod((int) $cabinet->id, $settings, $request);

        $list = ['items' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => OzStockHistoryService::PER_PAGE, 'total' => 0]];
        if ($tracking['has_history']) {
            $list = $this->stockHistoryService->listProducts((int) $cabinet->id, $request, $period);
        }

        return Inertia::render('Subscriber/Oz/StockHistory/Index', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
            ],
            'tracking' => $tracking,
            'filters' => [
                'search' => (string) $request->input('search', ''),
                'from' => $period['from'],
                'to' => $period['to'],
                'page' => (int) $request->input('page', 1),
            ],
            'dates' => $period['dates'],
            'products' => $list['items'],
            'productsMeta' => $list['meta'],
        ]);
    }

    public function status(Request $request): JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if (! $cabinetOrResponse instanceof OzCabinet) {
            return $cabinetOrResponse;
        }

        $settings = $this->stockHistoryService->settingsFor((int) $cabinetOrResponse->id);

        return response()->json([
            'success' => true,
            'messages' => [],
            'data' => $this->stockHistoryService->trackingPayload($settings),
        ]);
    }

    public function products(Request $request): JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if (! $cabinetOrResponse instanceof OzCabinet) {
            return $cabinetOrResponse;
        }

        $settings = $this->stockHistoryService->settingsFor((int) $cabinetOrResponse->id);
        $period = $this->stockHistoryService->resolvePeriod((int) $cabinetOrResponse->id, $settings, $request);
        $list = $this->stockHistoryService->listProducts((int) $cabinetOrResponse->id, $request, $period);

        return response()->json([
            'success' => true,
            'messages' => [],
            'data' => [
                'items' => $list['items'],
                'meta' => $list['meta'],
                'dates' => $period['dates'],
                'from' => $period['from'],
                'to' => $period['to'],
            ],
        ]);
    }

    public function product(Request $request, int $sku): JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if (! $cabinetOrResponse instanceof OzCabinet) {
            return $cabinetOrResponse;
        }

        $settings = $this->stockHistoryService->settingsFor((int) $cabinetOrResponse->id);
        $period = $this->stockHistoryService->resolvePeriod((int) $cabinetOrResponse->id, $settings, $request);
        $detail = $this->stockHistoryService->productHistory(
            (int) $cabinetOrResponse->id,
            $sku,
            $period['dates'],
            $period['from'],
            $period['to'],
        );

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

    public function start(Request $request): RedirectResponse|JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinet($request, self::TOOL_NAME, $this->breadcrumbs());
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var OzCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $result = $this->stockHistoryService->startTracking($cabinet);
        if (! ($result['success'] ?? false)) {
            return $this->toolResponse($request, $result, 422);
        }

        return $this->toolResponse($request, $result);
    }

    public function stop(Request $request): RedirectResponse|JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinet($request, self::TOOL_NAME, $this->breadcrumbs());
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var OzCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $result = $this->stockHistoryService->stopTracking((int) $cabinet->id);

        return $this->toolResponse($request, $result);
    }

    public function updateSettings(UpdateOzStockHistorySettingsRequest $request): RedirectResponse|JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinet($request, self::TOOL_NAME, $this->breadcrumbs());
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var OzCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $result = $this->stockHistoryService->updateRetention(
            (int) $cabinet->id,
            (int) $request->validated('retention_days'),
        );

        return $this->toolResponse($request, $result);
    }

    public function sync(Request $request): RedirectResponse|JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinet($request, self::TOOL_NAME, $this->breadcrumbs());
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var OzCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $result = $this->stockHistoryService->retryYesterdaySnapshot((int) $cabinet->id);
        if (! ($result['success'] ?? false)) {
            return $this->toolResponse($request, $result, 422);
        }

        return $this->toolResponse($request, $result);
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

        $flash = $success ? 'success' : 'error';

        return back()->with($flash, implode(' ', $messages));
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
