<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Repricer;

use App\Http\Controllers\Web\Subscriber\Concerns\ResolvesSelectedWbCabinet;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\RepricerLogsRequest;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Subscriber\Wb\RepricerCabinetsService;
use App\Services\Subscriber\Wb\WbCabinetService;
use App\Support\ToolLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CabinetsController extends SubscriberToolController
{
    use ResolvesSelectedWbCabinet;

    public function __construct(
        private readonly RepricerCabinetsService $cabinetsService,
        private readonly WbCabinetService $wbCabinets,
    ) {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $cabinet = $this->wbCabinets->selectedFor($request->user());

        if (! $cabinet) {
            return Inertia::render('Subscriber/Wb/Shared/NoCabinet', [
                'toolName' => 'Репрайсер цен Wildberries',
                'breadcrumbs' => [
                    ['label' => 'Главная', 'href' => '/panel'],
                    ['label' => 'Репрайсер цен Wildberries'],
                ],
            ]);
        }

        return redirect()->route('subscriber.wb.repricer.index');
    }

    public function store(): RedirectResponse
    {
        return redirect()
            ->route('subscriber.wb.cabinets.index')
            ->with('error', 'Создавайте кабинеты на странице «Общие кабинеты».');
    }

    public function update(): RedirectResponse
    {
        return redirect()
            ->route('subscriber.wb.cabinets.index')
            ->with('error', 'Управляйте кабинетами на странице «Общие кабинеты».');
    }

    public function destroy(): RedirectResponse
    {
        return redirect()
            ->route('subscriber.wb.cabinets.index')
            ->with('error', 'Управляйте кабинетами на странице «Общие кабинеты».');
    }

    public function logs(RepricerLogsRequest $request): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $response = $this->cabinetsService->getLogs(
            $request->duplicate(null, array_merge(
                $request->validated(),
                ['cabinet_id' => $cabinet->id]
            ))
        );
        $payload = $this->decodeApiResponse($response);

        return response()->json($payload);
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
