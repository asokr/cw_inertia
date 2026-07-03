<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Repricer;

use App\Http\Controllers\Api\Subscriber\Wb\RePricer\RepricerCabinetsController as ApiRepricerCabinetsController;
use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresRepricerCabinetOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\RepricerLogsRequest;
use App\Http\Requests\Web\Subscriber\StoreRepricerCabinetRequest;
use App\Http\Requests\Web\Subscriber\UpdateRepricerCabinetRequest;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CabinetsController extends SubscriberToolController
{
    use EnsuresRepricerCabinetOwnership;

    public function __construct(
        private readonly ApiRepricerCabinetsController $apiCabinetsController,
    ) {
    }

    public function index(Request $request): Response
    {
        $response = $this->apiCabinetsController->index();
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
                    'href' => route('subscriber.wb.repricer.cabinets.show', $row['id']),
                ];
            }
        }

        return Inertia::render('Subscriber/Wb/Repricer/Index', [
            'cabinets' => $cabinets,
            'limits' => $this->repricerLimits($request),
        ]);
    }

    public function store(StoreRepricerCabinetRequest $request): RedirectResponse
    {
        $response = $this->apiCabinetsController->store($request);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось добавить кабинет'));
        }

        return redirect()
            ->route('subscriber.wb.repricer.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет добавлен'));
    }

    public function update(UpdateRepricerCabinetRequest $request, RepricerCabinets $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->apiCabinetsController->update(
            $request->duplicate(null, $request->validated()),
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

    public function destroy(RepricerCabinets $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->apiCabinetsController->destroy((string) $cabinet->id);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось удалить кабинет'));
        }

        return redirect()
            ->route('subscriber.wb.repricer.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет удалён'));
    }

    public function logs(RepricerLogsRequest $request, RepricerCabinets $cabinet): JsonResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->apiCabinetsController->getLogs(
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

        $limits = ['repricer_nmid' => null];

        if ($subscription && isset($subscription->limits_plan['repricer_nmid'])) {
            $limits['repricer_nmid'] = (int) $subscription->limits_plan['repricer_nmid'];
        }

        return $limits;
    }
}