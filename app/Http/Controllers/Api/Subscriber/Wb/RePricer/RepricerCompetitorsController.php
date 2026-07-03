<?php

namespace App\Http\Controllers\Api\Subscriber\Wb\RePricer;

use App\Http\Controllers\Controller;
use App\Http\Traits\WBApiTrait;
use App\Http\Traits\WBadvTrait;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerCompetitor;
use App\Models\WbSearchRequest;
use App\Services\Wb\WbSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class RepricerCompetitorsController extends Controller
{
    use WBApiTrait, WBadvTrait;

    private WbSearchService $wbSearchService;

    public function __construct(WbSearchService $wbSearchService)
    {
        $this->wbSearchService = $wbSearchService;
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'nullable|integer|exists:wb_repricer_cabinets,id',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $userId = auth()->id();

        if ($request->filled('cabinet_id')) {
            if (!$this->cabinetBelongsToUser((int) $request->cabinet_id, $userId)) {
                return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
            }
        }

        $competitors = RepricerCompetitor::query()
            ->whereHas('cabinet', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->when($request->filled('cabinet_id'), function ($query) use ($request) {
                $query->where('cabinet_id', $request->cabinet_id);
            })
            ->orderByDesc('id')
            ->get();

        return response()->json([
            "success" => true,
            "messages" => ["Список номенклатур"],
            "data" => $competitors,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required|integer|exists:wb_repricer_cabinets,id',
            'nm_id' => [
                'required',
                'integer',
                Rule::unique('wb_repricer_competitors', 'nm_id')->where(function ($query) use ($request) {
                    return $query->where('cabinet_id', $request->cabinet_id ?? 0);
                }),
            ],
            'competitors' => 'nullable|array',
            'difference' => 'nullable|numeric',
            'difference_type' => 'nullable|in:percent,amount',
            'competitors_price_type' => 'nullable|in:min,average,max',
            'status' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        if (!$this->cabinetBelongsToUser((int) $request->cabinet_id)) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        $competitor = RepricerCompetitor::create([
            'cabinet_id' => $request->cabinet_id,
            'nm_id' => $request->nm_id,
            'product_data' => $request->product_data,
            'competitors' => $request->competitors,
            'difference' => $request->difference,
            'difference_type' => $request->difference_type,
            'competitors_price_type' => $request->competitors_price_type,
            'status' => $request->boolean('status'),
        ]);

        $competitor->refresh();

        if (!$competitor) {
            return response()->json(["success" => false, "messages" => ["Не удалось сохранить данные"]], 200);
        }

        return response()->json([
            "success" => true,
            "messages" => ["Данные по конкурентам добавлены"],
            "data" => $competitor,
        ], 200);
    }

    public function show(string $id)
    {
        $competitor = RepricerCompetitor::find($id);

        if (!$competitor) {
            return response()->json(["success" => false, "messages" => ["Данных нет"]], 200);
        }

        if (!$this->competitorBelongsToUser($competitor)) {
            return response()->json(["success" => false, "messages" => ["Данных нет"]], 200);
        }

        return response()->json([
            "success" => true,
            "messages" => ["Информация по конкуренту"],
            "data" => $competitor,
        ], 200);
    }

    public function update(Request $request, string $id)
    {
        $competitor = RepricerCompetitor::find($id);

        if (!$competitor) {
            return response()->json(["success" => false, "messages" => ["Данных нет"]], 200);
        }

        if (!$this->competitorBelongsToUser($competitor)) {
            return response()->json(["success" => false, "messages" => ["Данных нет"]], 200);
        }

        $targetCabinetId = (int) ($request->cabinet_id ?? $competitor->cabinet_id);

        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'sometimes|required|integer|exists:wb_repricer_cabinets,id',
            'nm_id' => [
                'required',
                'integer',
                Rule::unique('wb_repricer_competitors', 'nm_id')
                    ->where(fn($query) => $query->where('cabinet_id', $targetCabinetId))
                    ->ignore($competitor->id),
            ],
            'competitors' => 'nullable|array',
            'difference' => 'nullable|numeric',
            'difference_type' => 'nullable|in:percent,amount',
            'competitors_price_type' => 'nullable|in:min,average,max',
            'status' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        if (!$this->cabinetBelongsToUser($targetCabinetId)) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        $nmIdChanged = (int) $request->nm_id !== (int) $competitor->nm_id;

        $competitor->cabinet_id = $targetCabinetId;
        $competitor->nm_id = $request->nm_id;

        if ($request->has('product_data')) {
            $competitor->product_data = $request->product_data;
        } elseif ($nmIdChanged) {
            $productData = $this->buildProductMeta((int) $request->nm_id);
            if (!empty($productData)) {
                $competitor->product_data = $productData;
            }
        }

        if ($request->has('competitors')) {
            $competitor->competitors = $request->competitors;
        }

        if ($request->has('difference')) {
            $competitor->difference = $request->difference;
        }

        if ($request->has('difference_type')) {
            $competitor->difference_type = $request->difference_type;
        }

        if ($request->has('competitors_price_type')) {
            $competitor->competitors_price_type = $request->competitors_price_type;
        }

        if ($request->has('status')) {
            $competitor->status = $request->boolean('status');
        }

        $competitor->save();
        $competitor->refresh();

        return response()->json([
            "success" => true,
            "messages" => ["Данные обновлены"],
            "data" => $competitor,
        ], 200);
    }

    public function destroy(string $id)
    {
        $competitor = RepricerCompetitor::find($id);

        if (!$competitor) {
            return response()->json(["success" => false, "messages" => ["Данных нет"]], 200);
        }

        if (!$this->competitorBelongsToUser($competitor)) {
            return response()->json(["success" => false, "messages" => ["Данных нет"]], 200);
        }

        $competitor->delete();

        return response()->json([
            "success" => true,
            "messages" => ["Данные удалены"],
        ], 200);
    }

    public function toggleStatus(Request $request, string $id)
    {
        $competitor = RepricerCompetitor::find($id);

        if (! $competitor || ! $this->competitorBelongsToUser($competitor)) {
            return response()->json([
                'success' => false,
                'messages' => ['Данных нет'],
            ], 200);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $competitor->status = $request->boolean('status');
        $competitor->save();

        return response()->json([
            'success' => true,
            'messages' => ['Статус изменён'],
            'data' => $competitor,
        ], 200);
    }

    public function getNmdata(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required|integer|exists:wb_repricer_cabinets,id',
            'nm_id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        if (! $this->cabinetBelongsToUser((int) $request->cabinet_id)) {
            return response()->json([
                "success" => false,
                "messages" => ["Такого кабинета нет"],
            ], 200);
        }

        $cabinet = RepricerCabinets::find($request->cabinet_id);
        $ownershipError = $this->ensureCabinetOwnsNm($cabinet, (int) $request->nm_id);

        if ($ownershipError !== null) {
            return response()->json([
                "success" => false,
                "messages" => $ownershipError,
            ], 200);
        }


        $product_data = $this->buildProductMeta((int) $request->nm_id);

        $competitor = RepricerCompetitor::where('cabinet_id', $request->cabinet_id)
            ->where('nm_id', $request->nm_id)
            ->first();

        if ($competitor) {
            $competitor->product_data = $product_data;
            $competitor->save();
        }

        return response()->json([
            "success" => true,
            "messages" => ["Информация о NMID получена"],
            "data" => $product_data,
        ], 200);
    }

    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:3',
        ], [
            'query.required' => 'Не указан поисковый запрос',
            'query.string' => 'Некорректный формат запроса',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $searchTerm = trim($request->input('query'));

        if ($searchTerm === '') {
            return response()->json([
                'success' => false,
                'messages' => ['Не указан поисковый запрос'],
            ], 200);
        }

        $searchRequest = WbSearchRequest::create([
            'user_id' => auth()->id(),
            'type' => 'search',
            'payload' => ['query' => $searchTerm],
            'status' => WbSearchRequest::STATUS_PENDING,
        ]);

        $dispatched = $this->wbSearchService->dispatchSearch($searchRequest->id, $searchTerm);

        if (!$dispatched) {
            $searchRequest->update([
                'status' => WbSearchRequest::STATUS_FAILED,
                'error' => 'Не удалось отправить запрос в сервис поиска',
            ]);

            return response()->json([
                'success' => false,
                'messages' => ['Не удалось отправить запрос в сервис поиска'],
                'data' => [
                    'request_id' => $searchRequest->id,
                    'status' => WbSearchRequest::STATUS_FAILED,
                ],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Запрос передан в обработку'],
            'data' => [
                'request_id' => $searchRequest->id,
                'status' => $searchRequest->status,
            ],
        ], 200);
    }

    public function searchStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'request_id' => 'required|integer|exists:wb_search_requests,id',
        ], [
            'request_id.required' => 'Не указан идентификатор запроса',
            'request_id.integer' => 'Некорректный идентификатор запроса',
            'request_id.exists' => 'Запрос не найден',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $searchRequest = WbSearchRequest::where('id', $request->request_id)
            ->where('user_id', auth()->id())
            ->first();

        if (!$searchRequest) {
            return response()->json([
                'success' => false,
                'messages' => ['Данных нет'],
            ], 200);
        }

        $products = $searchRequest->status === WbSearchRequest::STATUS_DONE
            ? $this->formatServiceProducts($searchRequest->data ?? [])
            : null;
        // Логируем красиво все данные из $request
        // Log::info('Webhook request received', [
        //     'request_data' => $products ? json_encode($products, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : null
        // ]);

        return response()->json([
            'success' => true,
            'messages' => ['Статус запроса получен'],
            'data' => [
                'request_id' => $searchRequest->id,
                'status' => $searchRequest->status,
                'result' => $products,
                'error' => $searchRequest->error,
            ],
        ], 200);
    }

    public function webhook(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'requestId' => 'required|integer|exists:wb_search_requests,id',
            'status' => 'nullable|string|in:pending,done,failed',
            'data' => 'nullable|array',
            'error' => 'nullable|string',
        ], [
            'requestId.required' => 'Не передан requestId',
            'requestId.integer' => 'Некорректный requestId',
            'requestId.exists' => 'Запрос не найден',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }



        $searchRequest = WbSearchRequest::find($request->requestId);

        if (!$searchRequest) {
            return response()->json([
                'success' => false,
                'messages' => ['Запрос не найден'],
            ], 200);
        }

        $status = $request->input('status', WbSearchRequest::STATUS_DONE);

        $searchRequest->update([
            'data' => $request->input('data'),
            'status' => $status,
            'error' => $request->input('error'),
        ]);

        if ($status === WbSearchRequest::STATUS_FAILED) {
            Log::warning('WB search service returned failed status', [
                'request_id' => $searchRequest->id,
                'error' => $request->input('error'),
            ]);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Данные по запросу обновлены'],
            'data' => [
                'request_id' => $searchRequest->id,
                'status' => $status,
            ],
        ], 200);
    }

    public function bulkCompetitors(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ], [
            'ids.required' => 'Не переданы номенклатуры',
            'ids.array' => 'Некорректный формат данных',
            'ids.min' => 'Не переданы номенклатуры',
            'ids.*.integer' => 'Некорректный идентификатор номенклатуры',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $ids = collect($request->input('ids', []))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return response()->json([
                'success' => false,
                'messages' => ['Не переданы корректные номенклатуры'],
            ], 200);
        }

        if ($ids->count() > 15) {
            return response()->json([
                'success' => false,
                'messages' => ['За один запрос можно передать не более 15 номенклатур'],
            ], 200);
        }

        $results = [];
        $failed = [];

        foreach ($ids as $nmId) {
            try {
                $nmData = $this->wbSearchService->product($nmId);
            } catch (\Throwable $exception) {
                Log::error('Failed to fetch bulk competitor NM data', [
                    'nm_id' => $nmId,
                    'message' => $exception->getMessage(),
                ]);
                $failed[] = $nmId;
                continue;
            }

            $product = $nmData;

            if ($product) {
                $formatted = $this->formatCompetitorItem($product);
                if (!empty($formatted)) {
                    $results[] = $formatted;
                    continue;
                }
            }

            $failed[] = $nmId;
        }

        $this->updateExistingCompetitors($results);

        return response()->json([
            'success' => true,
            'messages' => ['Данные по номенклатурам получены'],
            'data' => $results,
            'failed' => $failed,
        ], 200);
    }


    private function cabinetBelongsToUser(int $cabinetId, ?int $userId = null): bool
    {
        $userId = $userId ?? auth()->id();

        $cabinet = RepricerCabinets::find($cabinetId);
        if (!$cabinet) {
            return false;
        }

        return $cabinet->user_id === $userId;
    }

    private function competitorBelongsToUser(RepricerCompetitor $competitor): bool
    {
        return $competitor->cabinet && $competitor->cabinet->user_id === auth()->id();
    }

    private function ensureCabinetOwnsNm(?RepricerCabinets $cabinet, int $nmId): ?array
    {
        if (!$cabinet) {
            return ['Такого кабинета нет'];
        }

        $response = $this->parseApiResponse($this->apiGetPrices($cabinet->apikey, [
            'filterNmID' => $nmId,
            'limit' => 1,
        ]));

        $code = $response['code'] ?? null;

        if ($code === 200) {
            $goods = data_get($response, 'data.data.listGoods', []);

            return empty($goods) ? ['Номенклатура не найдена в кабинете'] : null;
        }

        if ($code === 401) {
            return ['Не верный ключ API'];
        }

        return ['Ошибка при проверке номенклатуры'];
    }

    private function buildProductMeta(int $nmId): array
    {
        try {
            $product = $this->wbSearchService->product($nmId);
        } catch (\Throwable $exception) {
            Log::error('Failed to fetch NM data for repricer competitor', [
                'nm_id' => $nmId,
                'message' => $exception->getMessage(),
            ]);
            return [];
        }


        if (!$product || !is_array($product)) {
            Log::warning('Пустые данные NM получены для конкурента в репрайсере', ['nm_id' => $nmId]);
            return [];
        }

        $priceProduct = null;
        $sizes = data_get($product, 'sizes', []);

        if (is_iterable($sizes)) {
            foreach ($sizes as $size) {
                $price_product = data_get($size, 'price.product');

                if ($price_product !== null && $priceProduct === null) {
                    $priceProduct = is_numeric($price_product) ? (float) $price_product / 100 : null;
                    break;
                }
            }
        }

        $productId = data_get($product, 'id');
        $photoCount = (int) data_get($product, 'pics', 0);

        $productImage = null;
        if ($productId && $photoCount) {
            $images = $this->getProductImages($photoCount, $productId);
            $productImage = data_get($images, '0.imageS');
        }

        // $meta = [];
        $meta = [
            'supplier' => data_get($product, 'supplier'),
            'name' => data_get($product, 'name'),
            'price' => $priceProduct,
            'image' => $productImage,
        ];

        // $competitorsResponse = $this->publicGetCompetitors($nmId);
        // if ($competitorsResponse && !empty($competitorsResponse['products'])) {
        //     $competitors = [];
        //     foreach (array_slice($competitorsResponse['products'], 0, 10) as $competitorItem) {
        //         $formatted = $this->formatCompetitorItem($competitorItem);
        //         if (!empty($formatted)) {
        //             $competitors[] = $formatted;
        //         }
        //     }

        //     if (!empty($competitors)) {
        //         $meta['competitors'] = $competitors;
        //     }
        // }

        return array_filter($meta, static fn($value) => $value !== null);
    }

    private function formatCompetitorItem($item): array
    {
        // if (!is_array($item) || empty($item)) {
        //     return [];
        // }

        $competitorId = data_get($item, 'id');

        if (!is_numeric($competitorId)) {
            return [];
        }

        $priceProduct = null;
        $sizes = data_get($item, 'sizes', []);
        if (is_iterable($sizes)) {
            foreach ($sizes as $size) {
                $price_product = data_get($size, 'price.product');

                if ($price_product !== null && $priceProduct === null) {
                    $priceProduct = is_numeric($price_product) ? (float) $price_product / 100 : null;
                    break;
                }
            }
        }

        $photoCount = (int) data_get($item, 'pics', data_get($item, 'media.photo_count', 0));
        $competitorImage = null;

        if ($photoCount > 0) {
            $images = $this->getProductImages($photoCount, (int) $competitorId);
            $competitorImage = data_get($images, '0.imageS');
        }

        return array_filter([
            'nm_id' => (int) $competitorId,
            'supplier' => data_get($item, 'supplier'),
            'name' => data_get($item, 'name'),
            'price' => $priceProduct,
            'image' => $competitorImage,
            'rating' => data_get($item, 'reviewRating'),
            'nmFeedbacks' => data_get($item, 'nmFeedbacks'),
        ], static fn($value) => $value !== null && $value !== '');
    }

    /**
     * Универсально форматирует массив продуктов, пришедший от микросервиса
     * (как плоский список, так и внутри ключа products).
     */
    private function formatServiceProducts($data): array
    {

        $products = [];

        if (is_array($data)) {
            $products = isset($data['products']) && is_array($data['products'])
                ? $data['products']
                : $data;
        }

        if (!is_iterable($products)) {
            return [];
        }

        $results = [];

        foreach ($products as $product) {
            if (count($results) >= 15) {
                break;
            }

            $formatted = $this->formatCompetitorItem($product);
            if (!empty($formatted)) {
                $results[] = $formatted;
            }
        }

        return $results;
    }

    private function updateExistingCompetitors(array $items): void
    {
        if (empty($items)) {
            return;
        }

        foreach ($items as $item) {
            $nmId = (int) $item['nm_id'];

            // 1. Обновляем этот товар в списках конкурентов у других записей
            // Используем LIKE для поиска, так как JSON_CONTAINS может быть медленным или не работать с частичными структурами в зависимости от версии
            // Или лучше получить записи whereJsonContains, если база позволяет.
            // Для надежности и совместимости с форматом массива объектов:
            $competitorRows = RepricerCompetitor::where('competitors', 'like', "%\"nm_id\":$nmId%")
                ->orWhere('competitors', 'like', "%\"nm_id\": \"$nmId\"%")
                ->get();

            foreach ($competitorRows as $row) {
                $competitors = $row->competitors;
                $updated = false;

                if (is_array($competitors)) {
                    foreach ($competitors as &$comp) {
                        $compNmId = $comp['nm_id'] ?? null;
                        if ($compNmId && (int)$compNmId === $nmId) {
                            $comp['price'] = $item['price'];
                            $comp['name'] = $item['name'];
                            $comp['image'] = $item['image'];
                            $comp['rating'] = $item['rating'] ?? $comp['rating'] ?? null;
                            $comp['nmFeedbacks'] = $item['nmFeedbacks'] ?? $comp['nmFeedbacks'] ?? null;
                            $comp['supplier'] = $item['supplier'] ?? $comp['supplier'] ?? null;
                            $updated = true;
                        }
                    }
                    unset($comp); // break reference
                }

                if ($updated) {
                    $row->competitors = $competitors;
                    $row->save();
                }
            }
        }
    }
}
