<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\PriceCalc;

use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresWbPriceCalcCabinetOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\ImportWbPriceCalcExcelRequest;
use App\Http\Requests\Web\Subscriber\ImportWbPriceCalcVolumeRequest;
use App\Http\Requests\Web\Subscriber\SaveWbPriceCalcSettingsRequest;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV3Data;
use App\Services\Subscriber\Wb\WbPriceCalculationV3Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceController extends SubscriberToolController
{
    use EnsuresWbPriceCalcCabinetOwnership;

    public function __construct(
        private readonly WbPriceCalculationV3Service $v3Service,
    ) {
    }

    public function show(Request $request, PriceCalculationCabinets $cabinet): Response
    {
        $this->ensureCabinetOwnership($cabinet);

        $settingsPayload = $this->decodeApiResponse(
            $this->v3Service->getSettings((int) $cabinet->id)
        );

        $cardsPayload = $this->decodeApiResponse(
            $this->v3Service->index($request, (int) $cabinet->id)
        );

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
                'per_page' => (int) $request->input('per_page', 250),
                'sort_key' => $request->input('sort_key'),
                'sort_dir' => $request->input('sort_dir', 'asc'),
                'search' => $request->input('search', ''),
            ],
        ]);
    }

    public function sync(Request $request, PriceCalculationCabinets $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->v3Service->syncCards(
            $this->apiRequestWith($request, ['cabinet_id' => $cabinet->id])
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось загрузить номенклатуру'));
        }

        $total = PriceCalculationV3Data::query()
            ->where('cabinet_id', $cabinet->id)
            ->count();

        $message = $this->apiMessage($payload, 'Номенклатура загружена');

        if ($total > 0) {
            $message .= " В таблице {$total} ".($total === 1 ? 'позиция' : ($total < 5 ? 'позиции' : 'позиций')).'.';
        } else {
            $message .= ' Товары не найдены — проверьте API-ключ кабинета.';
        }

        return back()->with('success', $message);
    }

    public function saveSettings(SaveWbPriceCalcSettingsRequest $request, PriceCalculationCabinets $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->v3Service->saveSettings(
            $this->apiRequestWith($request, array_merge(
                $request->validated(),
                ['cabinet_id' => $cabinet->id]
            ))
        );
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

        $response = $this->v3Service->importVolumes(
            $this->apiRequestWith($request, [
                'cabinet_id' => $cabinet->id,
                'file' => $request->file('file'),
            ])
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Импорт объёмов не выполнен'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Объёмы загружены'));
    }

    public function importExcel(ImportWbPriceCalcExcelRequest $request, PriceCalculationCabinets $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->v3Service->importExcel(
            $this->apiRequestWith($request, [
                'cabinet_id' => $cabinet->id,
                'file' => $request->file('file'),
            ])
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Импорт Excel не выполнен'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Данные импортированы и рассчитаны'));
    }

    public function exportExcel(Request $request, PriceCalculationCabinets $cabinet): RedirectResponse|StreamedResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->v3Service->exportExcel(
            $this->apiRequestWith($request, ['cabinet_id' => $cabinet->id])
        );
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
            'per_page' => (int) ($cardsData['per_page'] ?? $request->input('per_page', 250)),
            'total' => (int) ($cardsData['total'] ?? 0),
            'last_page' => (int) ($cardsData['last_page'] ?? 1),
        ];
    }
}