<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\AiCabinetAnalyzer;

use App\Http\Controllers\Api\Subscriber\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerCabinetsController as ApiAiCabinetAnalyzerCabinetsController;
use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresAiCabinetAnalyzerOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\StoreAiCabinetAnalyzerCabinetRequest;
use App\Http\Requests\Web\Subscriber\UpdateAiCabinetAnalyzerCabinetRequest;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerCabinet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CabinetsController extends SubscriberToolController
{
    use EnsuresAiCabinetAnalyzerOwnership;

    public function __construct(
        private readonly ApiAiCabinetAnalyzerCabinetsController $apiCabinetsController,
    ) {
    }

    public function index(Request $request): Response
    {
        $response = $this->apiCabinetsController->index($request);
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
                    'href' => route('subscriber.wb.ai-cabinet-analyzer.cabinets.show', $row['id']),
                ];
            }
        }

        return Inertia::render('Subscriber/Wb/AiCabinetAnalyzer/Index', [
            'cabinets' => $cabinets,
        ]);
    }

    public function store(StoreAiCabinetAnalyzerCabinetRequest $request): RedirectResponse
    {
        $response = $this->apiCabinetsController->store($request);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()
                ->withInput()
                ->with('error', $this->apiMessage($payload, 'Не удалось добавить кабинет'));
        }

        return redirect()
            ->route('subscriber.wb.ai-cabinet-analyzer.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет добавлен'));
    }

    public function update(UpdateAiCabinetAnalyzerCabinetRequest $request, AiCabinetAnalyzerCabinet $cabinet): RedirectResponse
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

    public function destroy(AiCabinetAnalyzerCabinet $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->apiCabinetsController->destroy(request(), (string) $cabinet->id);
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось удалить кабинет'));
        }

        return redirect()
            ->route('subscriber.wb.ai-cabinet-analyzer.index')
            ->with('success', $this->apiMessage($payload, 'Кабинет удалён'));
    }
}