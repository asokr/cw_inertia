<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\AbTesting;

use App\Http\Controllers\Web\Subscriber\Concerns\ResolvesSelectedWbCabinet;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Subscriber\Wb\WbAbTestingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends SubscriberToolController
{
    use ResolvesSelectedWbCabinet;

    private const TOOL_NAME = 'A/B-тестирование';

    public function __construct(
        private readonly WbAbTestingService $abTestingService,
    ) {
    }

    public function show(Request $request): Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, self::TOOL_NAME, [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => self::TOOL_NAME],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $list = $this->abTestingService->listProducts((int) $cabinet->id, $request);

        return Inertia::render('Subscriber/Wb/AbTesting/Index', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
            ],
            'products' => $list['items'],
            'productsMeta' => $list['meta'],
            'filters' => [
                'page' => (int) $request->input('page', 1),
                'per_page' => (int) $request->input('per_page', 25),
                'search' => (string) $request->input('search', ''),
            ],
        ]);
    }

    public function sync(Request $request): RedirectResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, self::TOOL_NAME, [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => self::TOOL_NAME],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $result = $this->abTestingService->syncProducts($cabinet);

        if (! ($result['success'] ?? false)) {
            return back()->with('error', implode(' ', $result['messages'] ?? ['Не удалось обновить список товаров']));
        }

        return back()->with('success', implode(' ', $result['messages'] ?? ['Список товаров обновлён']));
    }
}
