<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Profitability;

use App\Http\Controllers\Api\Subscriber\Wb\Profitability\ProfitabilityCabinetsController as ApiProfitabilityCabinetsController;
use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresWbProfitabilityCabinetOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StoreProfitabilityCabinetRequest;
use App\Http\Requests\Web\Subscriber\UpdateProfitabilityCabinetRequest;
use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CabinetsController extends SubscriberToolController
{
    use EnsuresWbProfitabilityCabinetOwnership;

    public function __construct(
        private readonly ApiProfitabilityCabinetsController $apiCabinetsController,
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
                    'href' => route('subscriber.wb.profitability.cabinets.show', $row['id']),
                ];
            }
        }

        return Inertia::render('Subscriber/Wb/Profitability/Index', [
            'cabinets' => $cabinets,
        ]);
    }

    public function store(StoreProfitabilityCabinetRequest $request): RedirectResponse
    {
        $response = $this->apiCabinetsController->store($request);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось добавить кабинет'));
        }

        return redirect()
            ->route('subscriber.wb.profitability.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет добавлен'));
    }

    public function update(UpdateProfitabilityCabinetRequest $request, ProfitabilityCabinet $cabinet): RedirectResponse
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

    public function destroy(ProfitabilityCabinet $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->apiCabinetsController->destroy((string) $cabinet->id);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось удалить кабинет'));
        }

        return redirect()
            ->route('subscriber.wb.profitability.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет удалён'));
    }
}