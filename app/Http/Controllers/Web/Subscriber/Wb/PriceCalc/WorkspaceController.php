<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\PriceCalc;

use App\Http\Controllers\Web\Subscriber\Concerns\ResolvesSelectedWbCabinet;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\ImportWbPriceCalcExcelRequest;
use App\Http\Requests\Web\Subscriber\ImportWbPriceCalcVolumeRequest;
use App\Http\Requests\Web\Subscriber\SaveWbPriceCalcSettingsRequest;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Subscriber\Wb\WbPriceCalculationV3Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceController extends SubscriberToolController
{
    use ResolvesSelectedWbCabinet;

    public function __construct(
        private readonly WbPriceCalculationV3Service $v3Service,
    ) {
    }

    public function show(Request $request): Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Ценообразование', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Ценообразование'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

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
            'operationLock' => $this->v3Service->getOperationLockState((int) $cabinet->id),
            'jobStatus' => $this->v3Service->getJobStatus((int) $cabinet->id),
            'filters' => [
                'page' => (int) $request->input('page', 1),
                'per_page' => (int) $request->input('per_page', 250),
                'sort_key' => $request->input('sort_key'),
                'sort_dir' => $request->input('sort_dir', 'asc'),
                'search' => $request->input('search', ''),
            ],
        ]);
    }

    public function sync(Request $request): RedirectResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Ценообразование', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Ценообразование'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $response = $this->v3Service->syncCards(
            $this->apiRequestWith($request, ['cabinet_id' => $cabinet->id])
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return $this->backWithPriceCalcResult($payload, false, 'Не удалось запустить обновление');
        }

        return $this->backAfterQueuedOperation(
            (int) $cabinet->id,
            $this->apiMessage($payload, 'Обновление списка товаров запущено. Это может занять несколько минут.')
        );
    }

    public function saveSettings(SaveWbPriceCalcSettingsRequest $request): RedirectResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Ценообразование', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Ценообразование'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

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

    public function importVolume(ImportWbPriceCalcVolumeRequest $request): RedirectResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Ценообразование', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Ценообразование'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

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

    public function importExcel(ImportWbPriceCalcExcelRequest $request): RedirectResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Ценообразование', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Ценообразование'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $response = $this->v3Service->importExcel(
            $this->apiRequestWith($request, [
                'cabinet_id' => $cabinet->id,
                'file' => $request->file('file'),
            ])
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return $this->backWithPriceCalcResult($payload, false, 'Не удалось запустить импорт');
        }

        return $this->backAfterQueuedOperation(
            (int) $cabinet->id,
            $this->apiMessage(
                $payload,
                'Импорт и пересчёт цен запущены. Это может занять несколько минут — дождитесь уведомления.'
            )
        );
    }

    /**
     * After dispatch: if the queue is sync (or job already finished), surface final toast.
     */
    private function backAfterQueuedOperation(int $cabinetId, string $queuedMessage): RedirectResponse
    {
        $status = $this->v3Service->getJobStatus($cabinetId);

        if (($status['status'] ?? null) === 'failed') {
            $redirect = back()->with('error', (string) ($status['error'] ?? 'Не удалось выполнить операцию'));
            $retryAfter = (int) ($this->v3Service->getOperationLockState($cabinetId)['retry_after'] ?? 0);
            if ($retryAfter > 0) {
                $redirect->with('price_calc_retry_after', $retryAfter);
            }

            return $redirect;
        }

        if (($status['status'] ?? null) === 'done' && ! empty($status['success_message'])) {
            $redirect = back()->with('success', (string) $status['success_message']);
            $retryAfter = (int) ($this->v3Service->getOperationLockState($cabinetId)['retry_after'] ?? 0);
            if ($retryAfter > 0) {
                $redirect->with('price_calc_retry_after', $retryAfter);
            }

            return $redirect;
        }

        return back()->with('success', $queuedMessage);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function backWithPriceCalcResult(array $payload, bool $success, string $fallback): RedirectResponse
    {
        $message = $this->apiMessage($payload, $fallback);
        $retryAfter = (int) ($payload['retry_after'] ?? 0);

        if ($retryAfter <= 0 && ! $success) {
            $state = $this->v3Service->getOperationLockState(
                (int) ($payload['cabinet_id'] ?? 0)
            );
            if (($state['retry_after'] ?? 0) > 0) {
                $retryAfter = (int) $state['retry_after'];
            }
        }

        $redirect = $success
            ? back()->with('success', $message)
            : back()->with('error', $message);

        if ($retryAfter > 0) {
            $redirect->with('price_calc_retry_after', $retryAfter);
        }

        return $redirect;
    }

    public function exportExcel(Request $request): RedirectResponse|StreamedResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Ценообразование', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Ценообразование'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

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
