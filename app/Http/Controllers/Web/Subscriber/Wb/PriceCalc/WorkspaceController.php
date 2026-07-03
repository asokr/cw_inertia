<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\PriceCalc;

use App\Http\Controllers\Api\Subscriber\Wb\PriceCalculation\PriceCalculationV3Controller as ApiPriceCalculationV3Controller;
use App\Http\Controllers\Web\Subscriber\Concerns\DelegatesToApiGuard;
use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresWbPriceCalcCabinetOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\ImportWbPriceCalcExcelRequest;
use App\Http\Requests\Web\Subscriber\ImportWbPriceCalcVolumeRequest;
use App\Http\Requests\Web\Subscriber\SaveWbPriceCalcSettingsRequest;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceController extends SubscriberToolController
{
    use DelegatesToApiGuard;
    use EnsuresWbPriceCalcCabinetOwnership;

    public function __construct(
        private readonly ApiPriceCalculationV3Controller $apiV3Controller,
    ) {
    }

    public function show(Request $request, PriceCalculationCabinets $cabinet): Response
    {
        $this->ensureCabinetOwnership($cabinet);

        $settingsPayload = $this->withApiGuard($request, fn () => $this->decodeApiResponse(
            $this->apiV3Controller->getSettings((int) $cabinet->id)
        ));

        $cardsPayload = $this->withApiGuard($request, fn () => $this->decodeApiResponse(
            $this->apiV3Controller->index($request, (int) $cabinet->id)
        ));

        $settings = ($settingsPayload['success'] ?? false) ? ($settingsPayload['data'] ?? null) : null;
        $cardsData = ($cardsPayload['success'] ?? false) ? ($cardsPayload['data'] ?? []) : [];

        return Inertia::render('Subscriber/Wb/PriceCalc/Cabinet/Show', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
            ],
            'settings' => $settings,
            'cards' => $cardsData['data'] ?? [],
            'cardsMeta' => $this->buildCardsMeta($cardsData, $request),
            'cardsError' => ($cardsPayload['success'] ?? false) ? null : $this->apiMessage($cardsPayload, 'Не удалось загрузить номенклатуру'),
            'filters' => [
                'page' => (int) $request->input('page', 1),
                'per_page' => (int) $request->input('per_page', 25),
                'sort_key' => $request->input('sort_key'),
                'sort_dir' => $request->input('sort_dir', 'asc'),
                'search' => $request->input('search', ''),
            ],
        ]);
    }

    public function sync(Request $request, PriceCalculationCabinets $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->withApiGuard($request, fn () => $this->apiV3Controller->syncCards(
            $request->duplicate(null, ['cabinet_id' => $cabinet->id])
        ));
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось загрузить номенклатуру'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Номенклатура загружена'));
    }

    public function saveSettings(SaveWbPriceCalcSettingsRequest $request, PriceCalculationCabinets $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->withApiGuard($request, fn () => $this->apiV3Controller->saveSettings(
            $request->duplicate(null, array_merge(
                $request->validated(),
                ['cabinet_id' => $cabinet->id]
            ))
        ));
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось сохранить настройки'));
        }

        $message = $this->apiMessage($payload, 'Настройки сохранены');
        if ($request->boolean('hide_sizes') === false) {
            $message .= ' Нажмите «Обновить список товаров», чтобы загрузить все размеры.';
        }

        return back()->with('success', $message);
    }

    public function importVolume(ImportWbPriceCalcVolumeRequest $request, PriceCalculationCabinets $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->withApiGuard($request, fn () => $this->apiV3Controller->importVolumes(
            $request->duplicate(null, [
                'cabinet_id' => $cabinet->id,
                'file' => $request->file('file'),
            ])
        ));
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Импорт объёмов не выполнен'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Объёмы загружены'));
    }

    public function importExcel(ImportWbPriceCalcExcelRequest $request, PriceCalculationCabinets $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->withApiGuard($request, fn () => $this->apiV3Controller->importExcel(
            $request->duplicate(null, [
                'cabinet_id' => $cabinet->id,
                'file' => $request->file('file'),
            ])
        ));
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Импорт Excel не выполнен'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Данные импортированы и рассчитаны'));
    }

    public function exportExcel(Request $request, PriceCalculationCabinets $cabinet): RedirectResponse|StreamedResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->withApiGuard($request, fn () => $this->apiV3Controller->exportExcel(
            $request->duplicate(null, ['cabinet_id' => $cabinet->id])
        ));
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось выполнить экспорт'));
        }

        $path = "wb/price-calc-v3/{$cabinet->id}/price-data.xlsx";

        if (! Storage::disk('public')->exists($path)) {
            return back()->with('error', 'Файл экспорта не найден');
        }

        return Storage::disk('public')->download(
            $path,
            'price-calc-'.now()->format('Y-m-d').'.xlsx'
        );
    }

    /**
     * @param  array<string, mixed>  $cardsData
     * @return array<string, mixed>
     */
    private function buildCardsMeta(array $cardsData, Request $request): array
    {
        return [
            'current_page' => (int) ($cardsData['current_page'] ?? $request->input('page', 1)),
            'per_page' => (int) ($cardsData['per_page'] ?? $request->input('per_page', 25)),
            'total' => (int) ($cardsData['total'] ?? 0),
            'last_page' => (int) ($cardsData['last_page'] ?? 1),
        ];
    }
}