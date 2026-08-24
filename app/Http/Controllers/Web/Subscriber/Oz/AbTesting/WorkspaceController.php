<?php

namespace App\Http\Controllers\Web\Subscriber\Oz\AbTesting;

use App\Http\Controllers\Web\Subscriber\Concerns\ResolvesSelectedOzCabinet;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\PrepareOzAbCampaignRequest;
use App\Http\Requests\Web\Subscriber\ReorderAbExperimentPhotosRequest;
use App\Http\Requests\Web\Subscriber\ReplaceAbExperimentPhotoRequest;
use App\Http\Requests\Web\Subscriber\StoreAbExperimentPhotosRequest;
use App\Http\Requests\Web\Subscriber\StoreAbExperimentRequest;
use App\Http\Requests\Web\Subscriber\StoreOzAbCampaignRequest;
use App\Http\Requests\Web\Subscriber\UpdateAbExperimentRequest;
use App\Http\Requests\Web\Subscriber\UpdateOzAbExperimentSettingsRequest;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Services\Subscriber\Oz\OzAbTestingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends SubscriberToolController
{
    use ResolvesSelectedOzCabinet;

    private const TOOL_NAME = 'A/B-тестирование';

    public function __construct(
        private readonly OzAbTestingService $abTestingService,
    ) {
    }

    public function show(Request $request): Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinet($request, self::TOOL_NAME, [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => self::TOOL_NAME],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var OzCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $list = $this->abTestingService->listProducts((int) $cabinet->id, $request);

        $selectedProduct = null;
        $selectedExperiment = null;
        $experiments = [];

        $productId = $request->integer('product_id');
        $experimentId = $request->integer('experiment_id');

        if ($productId > 0) {
            $product = $this->abTestingService->getProductForCabinet((int) $cabinet->id, $productId);
            if ($product) {
                $selectedProduct = $this->abTestingService->mapProductDetail($product);
                $experiments = $this->abTestingService->listExperiments((int) $cabinet->id, (int) $product->id);

                if ($experimentId > 0) {
                    $experiment = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experimentId);
                    if ($experiment && (int) $experiment->ab_product_id === (int) $product->id) {
                        $experiment->load($this->abTestingService->experimentDetailRelations());
                        $selectedExperiment = $this->abTestingService->mapExperiment($experiment);
                    }
                }
            }
        }

        return Inertia::render('Subscriber/Oz/AbTesting/Index', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
                'has_performance_credentials' => trim((string) $cabinet->performance_client_id) !== ''
                    && trim((string) $cabinet->performance_client_secret) !== '',
            ],
            'products' => $list['items'],
            'productsMeta' => $list['meta'],
            'filters' => [
                'page' => (int) $request->input('page', 1),
                'per_page' => (int) $request->input('per_page', 25),
                'search' => (string) $request->input('search', ''),
                'product_id' => $productId > 0 ? $productId : null,
                'experiment_id' => $experimentId > 0 ? $experimentId : null,
            ],
            'selectedProduct' => $selectedProduct,
            'selectedExperiment' => $selectedExperiment,
            'experiments' => $experiments,
            'createdExperiment' => null,
        ]);
    }

    public function sync(Request $request): RedirectResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinet($request, self::TOOL_NAME, [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => self::TOOL_NAME],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var OzCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $result = $this->abTestingService->syncProducts($cabinet);
        if (! ($result['success'] ?? false)) {
            return back()->with('error', implode(' ', $result['messages'] ?? ['Не удалось обновить список товаров']));
        }

        return back()->with('success', implode(' ', $result['messages'] ?? ['Список товаров обновлён']));
    }

    public function storeExperiment(StoreAbExperimentRequest $request): RedirectResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinet($request, self::TOOL_NAME, [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => self::TOOL_NAME],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var OzCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $productId = (int) $request->validated('product_id');
        $product = $this->abTestingService->getProductForCabinet((int) $cabinet->id, $productId);
        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => 'Товар не найден в выбранном кабинете.',
            ]);
        }

        $experiment = $this->abTestingService->createDraftExperiment(
            $cabinet,
            $product,
            $request->validated('name') ?? null,
        );

        return redirect()
            ->route('subscriber.oz.ab-testing.index', [
                'product_id' => $product->id,
            ])
            ->with('success', 'Эксперимент создан')
            ->with('createdExperiment', $this->abTestingService->mapExperiment($experiment));
    }

    public function updateExperiment(UpdateAbExperimentRequest $request, int $experiment): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json(['success' => false, 'messages' => ['Эксперимент не найден.']], 404);
        }

        $updated = $this->abTestingService->renameExperiment($model, (string) $request->validated('name'));

        return response()->json([
            'success' => true,
            'experiment' => $this->abTestingService->mapExperiment($updated),
            'messages' => ['Название сохранено'],
        ]);
    }

    public function startExperiment(Request $request, int $experiment): JsonResponse
    {
        return $this->jsonExperimentAction(
            $request,
            $experiment,
            fn (OzCabinet $cabinet, $model) => $this->abTestingService->startExperiment($cabinet, $model),
        );
    }

    public function stopExperiment(Request $request, int $experiment): JsonResponse
    {
        return $this->jsonExperimentAction(
            $request,
            $experiment,
            fn (OzCabinet $cabinet, $model) => $this->abTestingService->stopExperiment($cabinet, $model),
        );
    }

    public function updateSettings(UpdateOzAbExperimentSettingsRequest $request, int $experiment): JsonResponse
    {
        return $this->jsonExperimentAction(
            $request,
            $experiment,
            fn (OzCabinet $cabinet, $model) => $this->abTestingService->updateExperimentSettings(
                $cabinet,
                $model,
                $request->validated(),
            ),
        );
    }

    public function listCampaigns(Request $request): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $experiment = $this->abTestingService->getExperimentForCabinet(
            (int) $cabinet->id,
            $request->integer('experiment_id'),
        );
        if (! $experiment) {
            return response()->json(['success' => false, 'messages' => ['Эксперимент не найден.']], 404);
        }

        $experiment->loadMissing('product');
        $result = $this->abTestingService->listCampaignsForExperiment($cabinet, $experiment);

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'campaigns' => $result['items'] ?? [],
            'experiment' => $this->abTestingService->mapExperiment($experiment),
            'messages' => $result['messages'] ?? [],
            'default_campaign_name' => $experiment->product
                ? $this->abTestingService->defaultCampaignName($experiment->product)
                : null,
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function storeCampaign(StoreOzAbCampaignRequest $request): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $experiment = $this->abTestingService->getExperimentForCabinet(
            (int) $cabinet->id,
            (int) $request->validated('experiment_id'),
        );
        if (! $experiment) {
            return response()->json(['success' => false, 'messages' => ['Эксперимент не найден.']], 404);
        }

        try {
            $result = $this->abTestingService->createAndBindCampaign($cabinet, $experiment, $request->validated());
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'messages' => collect($e->errors())->flatten()->values()->all(),
            ], 422);
        }

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'experiment' => $result['experiment'] ?? null,
            'campaign' => $result['campaign'] ?? null,
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function prepareCampaign(PrepareOzAbCampaignRequest $request, int $campaignId): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $experiment = $this->abTestingService->getExperimentForCabinet(
            (int) $cabinet->id,
            (int) $request->validated('experiment_id'),
        );
        if (! $experiment) {
            return response()->json(['success' => false, 'messages' => ['Эксперимент не найден.']], 404);
        }

        try {
            $result = $this->abTestingService->prepareCampaignForProduct($cabinet, $experiment, $campaignId);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'messages' => collect($e->errors())->flatten()->values()->all(),
            ], 422);
        }

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'experiment' => $result['experiment'] ?? null,
            'campaign' => $result['campaign'] ?? null,
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function pauseCampaign(Request $request, int $campaignId): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        try {
            $result = $this->abTestingService->pauseCampaign($cabinet, $campaignId);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'messages' => collect($e->errors())->flatten()->values()->all(),
            ], 422);
        }

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function deleteCampaign(Request $request, int $campaignId): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        try {
            $result = $this->abTestingService->deleteCampaign($cabinet, $campaignId);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'messages' => collect($e->errors())->flatten()->values()->all(),
            ], 422);
        }

        $experiment = null;
        $experimentId = $request->integer('experiment_id');
        if ($experimentId > 0) {
            $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experimentId);
            if ($model) {
                $experiment = $this->abTestingService->mapExperiment($model->fresh());
            }
        }

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'experiment' => $experiment,
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function listPhotos(Request $request, int $experiment): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json(['success' => false, 'messages' => ['Эксперимент не найден.']], 404);
        }
        $model->load('photos');

        return response()->json([
            'success' => true,
            'photos' => $this->abTestingService->listPhotos($model),
            'experiment' => $this->abTestingService->mapExperiment($model),
            'messages' => [],
        ]);
    }

    public function storePhotos(StoreAbExperimentPhotosRequest $request, int $experiment): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json(['success' => false, 'messages' => ['Эксперимент не найден.']], 404);
        }

        try {
            $result = $this->abTestingService->storePhotos($cabinet, $model, $request->file('photos', []));
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'messages' => collect($e->errors())->flatten()->values()->all(),
            ], 422);
        }

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'photos' => $result['photos'] ?? [],
            'experiment' => $result['experiment'] ?? null,
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function replacePhoto(ReplaceAbExperimentPhotoRequest $request, int $experiment, int $photo): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json(['success' => false, 'messages' => ['Эксперимент не найден.']], 404);
        }

        $photoModel = $model->photos()->whereKey($photo)->first();
        if (! $photoModel) {
            return response()->json(['success' => false, 'messages' => ['Фотография не найдена.']], 404);
        }

        try {
            $result = $this->abTestingService->replacePhoto($cabinet, $model, $photoModel, $request->file('photo'));
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'messages' => collect($e->errors())->flatten()->values()->all(),
            ], 422);
        }

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'photos' => $result['photos'] ?? [],
            'experiment' => $result['experiment'] ?? null,
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function destroyPhoto(Request $request, int $experiment, int $photo): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json(['success' => false, 'messages' => ['Эксперимент не найден.']], 404);
        }

        $photoModel = $model->photos()->whereKey($photo)->first();
        if (! $photoModel) {
            return response()->json(['success' => false, 'messages' => ['Фотография не найдена.']], 404);
        }

        $result = $this->abTestingService->destroyPhoto($cabinet, $model, $photoModel);

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function reorderPhotos(ReorderAbExperimentPhotosRequest $request, int $experiment): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json(['success' => false, 'messages' => ['Эксперимент не найден.']], 404);
        }

        $result = $this->abTestingService->reorderPhotos(
            $cabinet,
            $model,
            $request->validated('order') ?? [],
        );

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'photos' => $result['photos'] ?? [],
            'experiment' => $result['experiment'] ?? null,
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function showMedia(Request $request, int $photo): HttpResponse|JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $photoModel = \App\Models\Subscribers\Oz\AbTesting\AbExperimentPhoto::query()
            ->where('cabinet_id', $cabinet->id)
            ->whereKey($photo)
            ->first();
        if (! $photoModel) {
            abort(404);
        }

        $binary = $this->abTestingService->readPhotoBinary($photoModel);
        if ($binary === null) {
            abort(404);
        }

        $headers = [
            'Content-Type' => $photoModel->mime ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
        ];
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="'.($photoModel->original_name ?: 'photo.jpg').'"';
        }

        return response($binary, 200, $headers);
    }

    /**
     * @param  callable(OzCabinet, mixed): array<string, mixed>  $action
     */
    private function jsonExperimentAction(Request $request, int $experiment, callable $action): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedOzCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json(['success' => false, 'messages' => ['Эксперимент не найден.']], 404);
        }

        try {
            $result = $action($cabinet, $model);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'messages' => collect($e->errors())->flatten()->values()->all(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'experiment' => $result['experiment'] ?? null,
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }
}
