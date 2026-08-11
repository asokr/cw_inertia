<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\AbTesting;

use App\Http\Controllers\Web\Subscriber\Concerns\ResolvesSelectedWbCabinet;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\BindAbCampaignRequest;
use App\Http\Requests\Web\Subscriber\DepositAbCampaignBudgetRequest;
use App\Http\Requests\Web\Subscriber\ModifyAbCampaignNmsRequest;
use App\Http\Requests\Web\Subscriber\ReorderAbExperimentPhotosRequest;
use App\Http\Requests\Web\Subscriber\ReplaceAbExperimentPhotoRequest;
use App\Http\Requests\Web\Subscriber\StoreAbCampaignRequest;
use App\Http\Requests\Web\Subscriber\StoreAbExperimentPhotosRequest;
use App\Http\Requests\Web\Subscriber\StoreAbExperimentRequest;
use App\Http\Requests\Web\Subscriber\UpdateAbExperimentRequest;
use App\Http\Requests\Web\Subscriber\UpdateAbExperimentSettingsRequest;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Subscriber\Wb\WbAbTestingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
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

        $selectedProduct = null;
        $selectedExperiment = null;
        $experiments = [];

        $productId = $request->integer('product_id');
        $experimentId = $request->integer('experiment_id');

        if ($productId > 0) {
            $product = $this->abTestingService->getProductForCabinet((int) $cabinet->id, $productId);
            if ($product) {
                $product = $this->abTestingService->enrichProductPrice($cabinet, $product);
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

    public function storeExperiment(StoreAbExperimentRequest $request): RedirectResponse|Response
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
            ->route('subscriber.wb.ab-testing.index', [
                'product_id' => $product->id,
            ])
            ->with('success', 'Эксперимент создан')
            ->with('createdExperiment', $this->abTestingService->mapExperiment($experiment));
    }

    public function updateExperiment(UpdateAbExperimentRequest $request, int $experiment): JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, self::TOOL_NAME, [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => self::TOOL_NAME],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return response()->json([
                'success' => false,
                'messages' => ['Добавьте хотя бы один кабинет Wildberries.'],
            ], 422);
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
        }

        $updated = $this->abTestingService->renameExperiment(
            $model,
            (string) $request->validated('name'),
        );

        return response()->json([
            'success' => true,
            'experiment' => $this->abTestingService->mapExperiment($updated),
            'messages' => ['Название сохранено'],
        ]);
    }

    public function startExperiment(Request $request, int $experiment): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
        }

        try {
            $result = $this->abTestingService->startExperiment($cabinet, $model);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'messages' => collect($e->errors())->flatten()->values()->all(),
                'errors' => $e->errors(),
            ], 422);
        }

        $status = ($result['success'] ?? false) ? 200 : 422;

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'experiment' => $result['experiment'] ?? null,
            'messages' => $result['messages'] ?? [],
        ], $status);
    }

    public function stopExperiment(Request $request, int $experiment): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
        }

        $result = $this->abTestingService->stopExperiment($cabinet, $model);
        $status = ($result['success'] ?? false) ? 200 : 422;

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'experiment' => $result['experiment'] ?? null,
            'messages' => $result['messages'] ?? [],
        ], $status);
    }

    public function updateSettings(UpdateAbExperimentSettingsRequest $request, int $experiment): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
        }

        try {
            $result = $this->abTestingService->updateExperimentSettings(
                $cabinet,
                $model,
                $request->validated(),
            );
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

    public function listCampaigns(Request $request): JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $experimentId = $request->integer('experiment_id');
        $experiment = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experimentId);
        if (! $experiment) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
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

    public function storeCampaign(StoreAbCampaignRequest $request): JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $experiment = $this->abTestingService->getExperimentForCabinet(
            (int) $cabinet->id,
            (int) $request->validated('experiment_id'),
        );
        if (! $experiment) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
        }

        try {
            $result = $this->abTestingService->createAndBindCampaign(
                $cabinet,
                $experiment,
                $request->validated(),
            );
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
            'budget_deposited' => $result['budget_deposited'] ?? null,
            'budget_error' => $result['budget_error'] ?? null,
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function bindCampaign(BindAbCampaignRequest $request, int $experiment): JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
        }

        try {
            $result = $this->abTestingService->bindCampaignToExperiment(
                $cabinet,
                $model,
                (int) $request->validated('advert_id'),
                $request->boolean('add_product', true),
            );
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

    public function prepareCampaign(ModifyAbCampaignNmsRequest $request, int $advertId): JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $experiment = $this->abTestingService->getExperimentForCabinet(
            (int) $cabinet->id,
            (int) $request->validated('experiment_id'),
        );
        if (! $experiment) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
        }

        try {
            $result = $this->abTestingService->prepareCampaignForProduct(
                $cabinet,
                $experiment,
                $advertId,
            );
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

    public function addCampaignProduct(ModifyAbCampaignNmsRequest $request, int $advertId): JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $experiment = $this->abTestingService->getExperimentForCabinet(
            (int) $cabinet->id,
            (int) $request->validated('experiment_id'),
        );
        if (! $experiment) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
        }

        try {
            $result = $this->abTestingService->addProductToCampaign(
                $cabinet,
                $experiment,
                $advertId,
                $request->boolean('bind', true),
            );
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

    public function removeCampaignProduct(ModifyAbCampaignNmsRequest $request, int $advertId): JsonResponse|Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        if (! $request->boolean('confirm')) {
            return response()->json([
                'success' => false,
                'messages' => ['Подтвердите удаление товара из рекламной кампании.'],
            ], 422);
        }

        $experiment = $this->abTestingService->getExperimentForCabinet(
            (int) $cabinet->id,
            (int) $request->validated('experiment_id'),
        );
        if (! $experiment) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
        }

        try {
            $result = $this->abTestingService->removeProductFromCampaign(
                $cabinet,
                $experiment,
                $advertId,
            );
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'messages' => collect($e->errors())->flatten()->values()->all(),
            ], 422);
        }

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'experiment' => $result['experiment'] ?? null,
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function pauseCampaign(Request $request, int $advertId): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $experiment = null;
        $experimentId = $request->integer('experiment_id');
        if ($experimentId > 0) {
            $experiment = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experimentId);
            if ($experiment) {
                $experiment->loadMissing('product');
            }
        }

        try {
            $result = $this->abTestingService->pauseCampaign($cabinet, $advertId, $experiment);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'messages' => collect($e->errors())->flatten()->values()->all(),
            ], 422);
        }

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'campaign' => $result['campaign'] ?? null,
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function deleteCampaign(Request $request, int $advertId): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $experiment = null;
        $experimentId = $request->integer('experiment_id');
        if ($experimentId > 0) {
            $experiment = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experimentId);
        }

        try {
            $result = $this->abTestingService->deleteCampaign($cabinet, $advertId, $experiment);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'messages' => collect($e->errors())->flatten()->values()->all(),
            ], 422);
        }

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'experiment' => $result['experiment'] ?? null,
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function getCampaignBudget(Request $request, int $advertId): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        try {
            $result = $this->abTestingService->getCampaignBudget($cabinet, $advertId);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'messages' => collect($e->errors())->flatten()->values()->all(),
            ], 422);
        }

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'budget' => $result['budget'] ?? null,
            'budget_total' => $result['budget_total'] ?? null,
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function depositCampaignBudget(DepositAbCampaignBudgetRequest $request, int $advertId): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        try {
            $result = $this->abTestingService->depositCampaignBudget(
                $cabinet,
                $advertId,
                (int) $request->validated('sum'),
            );
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'messages' => collect($e->errors())->flatten()->values()->all(),
            ], 422);
        }

        return response()->json([
            'success' => (bool) ($result['success'] ?? false),
            'budget' => $result['budget'] ?? null,
            'budget_total' => $result['budget_total'] ?? null,
            'deposited_sum' => $result['deposited_sum'] ?? null,
            'messages' => $result['messages'] ?? [],
        ], ($result['success'] ?? false) ? 200 : 422);
    }

    public function listPhotos(Request $request, int $experiment): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
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
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
        }

        try {
            $result = $this->abTestingService->storePhotos(
                $cabinet,
                $model,
                $request->file('photos', []) ?: [],
            );
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
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
        }

        $photoModel = $this->abTestingService->getPhotoForCabinet((int) $cabinet->id, $photo);
        if (! $photoModel) {
            return response()->json([
                'success' => false,
                'messages' => ['Фотография не найдена.'],
            ], 404);
        }

        try {
            $result = $this->abTestingService->replacePhoto(
                $cabinet,
                $model,
                $photoModel,
                $request->file('photo'),
            );
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
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
        }

        $photoModel = $this->abTestingService->getPhotoForCabinet((int) $cabinet->id, $photo);
        if (! $photoModel) {
            return response()->json([
                'success' => false,
                'messages' => ['Фотография не найдена.'],
            ], 404);
        }

        try {
            $result = $this->abTestingService->deletePhoto($cabinet, $model, $photoModel);
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

    public function reorderPhotos(ReorderAbExperimentPhotosRequest $request, int $experiment): JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $model = $this->abTestingService->getExperimentForCabinet((int) $cabinet->id, $experiment);
        if (! $model) {
            return response()->json([
                'success' => false,
                'messages' => ['Эксперимент не найден.'],
            ], 404);
        }

        try {
            $result = $this->abTestingService->reorderPhotos(
                $cabinet,
                $model,
                $request->validated('order'),
            );
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

    public function showMedia(Request $request, int $photo): HttpResponse|JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinetJson($request);
        if ($cabinetOrResponse instanceof JsonResponse) {
            return $cabinetOrResponse;
        }
        $cabinet = $cabinetOrResponse;

        $photoModel = $this->abTestingService->getPhotoForCabinet((int) $cabinet->id, $photo);
        if (! $photoModel) {
            abort(404);
        }

        $binary = $this->abTestingService->readPhotoBinary($photoModel);
        if ($binary === null) {
            abort(404);
        }

        $mime = $photoModel->mime ?: 'image/jpeg';
        $filename = $photoModel->original_name ?: basename((string) $photoModel->path);
        // Безопасное имя для Content-Disposition (без кавычек/переносов).
        $safeFilename = str_replace(['"', "\r", "\n", '\\'], '', $filename);
        if ($safeFilename === '') {
            $safeFilename = 'photo-'.$photoModel->id.'.jpg';
        }

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($binary, 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) strlen($binary),
            'Content-Disposition' => $disposition.'; filename="'.$safeFilename.'"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return WbCabinet|JsonResponse
     */
    private function requireSelectedWbCabinetJson(Request $request): WbCabinet|JsonResponse
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, self::TOOL_NAME, [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => self::TOOL_NAME],
        ]);

        if ($cabinetOrResponse instanceof Response) {
            return response()->json([
                'success' => false,
                'messages' => ['Добавьте хотя бы один кабинет Wildberries.'],
            ], 422);
        }

        return $cabinetOrResponse;
    }
}
