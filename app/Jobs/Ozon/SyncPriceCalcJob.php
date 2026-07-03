<?php

namespace App\Jobs\Ozon;

use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcCabinet;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbo;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbs;
use App\Services\Ozon\OzonApiService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SyncPriceCalcJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 час на всякий случай

    public function __construct(
        private readonly int $cabinetId,
        private readonly string $type
    ) {}

    public function handle(OzonApiService $ozonApiService): void
    {
        try {
            $this->process($ozonApiService);
        } catch (\Throwable $e) {
            Log::error("SyncPriceCalcJob failed: " . $e->getMessage());
            throw $e;
        } finally {
            $cacheKey = sprintf('ozon_price_calc_sync_%s_%s', $this->type, $this->cabinetId);
            Cache::forget($cacheKey);
        }
    }

    private function process(OzonApiService $ozonApiService): void
    {
        $cabinet = OzPriceCalcCabinet::find($this->cabinetId);

        if (! $cabinet) {
            Log::warning("SyncPriceCalcJob: Cabinet {$this->cabinetId} not found");
            return;
        }

        // Сбрасываем ошибку перед началом синхронизации
        $cabinet->update(['last_sync_error' => null]);

        $products = $this->fetchAllProducts($ozonApiService, $cabinet);

        if (! $products['success']) {
            $message = $products['message'];
            Log::error("SyncPriceCalcJob: Failed to fetch products for cabinet {$this->cabinetId}: " . $message);
            $cabinet->update(['last_sync_error' => $message]);
            return;
        }

        $productIds = Arr::pluck($products['items'], 'product_id');

        $modelClass = $this->type === 'fbo' ? OzPriceCalcFbo::class : OzPriceCalcFbs::class;

        if (empty($productIds)) {
            $modelClass::where('cabinet_id', $cabinet->id)->delete();
            return;
        }

        $detailsResponse = $this->fetchProductsDetails($ozonApiService, $cabinet, $productIds);

        if (! $detailsResponse['success']) {
            $message = $detailsResponse['message'];
            Log::error("SyncPriceCalcJob: Failed to fetch details for cabinet {$this->cabinetId}: " . $message);
            $cabinet->update(['last_sync_error' => $message]);
            return;
        }

        $attributesResponse = $this->fetchProductAttributes($ozonApiService, $cabinet, $productIds);
        $attributesMap = [];

        if ($attributesResponse['success']) {
            foreach ($attributesResponse['items'] as $item) {
                $attributesMap[$item['id']] = $item;
            }
        } else {
            Log::warning("SyncPriceCalcJob: Failed to fetch attributes for cabinet {$this->cabinetId}: " . $attributesResponse['message']);
        }

        $syncedIds = [];
        $details = $detailsResponse['items'];

        DB::transaction(function () use ($cabinet, $products, $details, &$syncedIds, $modelClass, $attributesMap) {
            foreach ($products['items'] as $product) {
                $productId = $product['product_id'];
                $offerId = $product['offer_id'] ?? (string) $productId;
                $detail = Arr::first($details, function ($item) use ($productId) {
                    return (int) ($item['id'] ?? 0) === (int) $productId;
                });

                $barcodes = [];
                $rawBarcodes = $detail !== null ? Arr::get($detail, 'barcodes', []) : [];

                if (is_array($rawBarcodes)) {
                    $barcodes = array_filter(
                        array_map('strval', $rawBarcodes),
                        fn($value) => $value !== ''
                    );
                }

                if (empty($barcodes)) {
                    $barcodes = [null];
                }

                foreach (array_unique($barcodes) as $barcode) {
                    $updateData = [];

                    $attr = $attributesMap[$productId] ?? null;
                    if ($attr) {
                        $updateData = array_merge($updateData, $this->parseAttributes($attr));
                    }

                    $model = $modelClass::updateOrCreate(
                        [
                            'cabinet_id' => $cabinet->id,
                            'ozon_article' => $offerId,
                            'barcode' => $barcode,
                        ],
                        $updateData
                    );

                    $syncedIds[] = $model->id;
                }
            }

            if (! empty($syncedIds)) {
                $modelClass::where('cabinet_id', $cabinet->id)
                    ->whereNotIn('id', $syncedIds)
                    ->delete();
            } else {
                $modelClass::where('cabinet_id', $cabinet->id)->delete();
            }
        });
    }

    private function fetchAllProducts(OzonApiService $ozonApiService, OzPriceCalcCabinet $cabinet): array
    {
        $items = [];
        $lastId = '';
        $limit = 1000;

        do {
            $payload = [
                'filter' => [
                    'visibility' => 'ALL',
                ],
                'last_id' => $lastId,
                'limit' => $limit,
            ];

            $response = $ozonApiService->getProductsList($cabinet->apikey, $cabinet->client_id, $payload);

            if (! $response['success']) {
                return [
                    'success' => false,
                    'message' => Arr::get($response, 'data.message', 'Не удалось получить список товаров'),
                ];
            }

            $batch = Arr::get($response, 'data.result.items', []);

            foreach ($batch as $item) {
                $items[] = [
                    'product_id' => $item['product_id'] ?? null,
                    'offer_id' => $item['offer_id'] ?? null,
                ];
            }

            $lastId = (string) Arr::get($response, 'data.result.last_id', '');
        } while (! empty($batch) && $lastId !== '');

        $items = array_filter($items, fn($item) => ! empty($item['product_id']));

        return [
            'success' => true,
            'items' => array_values($items),
        ];
    }

    private function fetchProductsDetails(OzonApiService $ozonApiService, OzPriceCalcCabinet $cabinet, array $productIds): array
    {
        $details = [];

        foreach (array_chunk($productIds, 999) as $chunk) {
            $response = $ozonApiService->getProductsInfo($cabinet->apikey, $cabinet->client_id, $chunk);

            if (! $response['success']) {
                return [
                    'success' => false,
                    'message' => Arr::get($response, 'data.message', 'Не удалось получить информацию о товарах'),
                ];
            }

            $items = Arr::get($response, 'data.items', []);

            $details = array_merge($details, $items);
        }

        return [
            'success' => true,
            'items' => $details,
        ];
    }

    private function fetchProductAttributes(OzonApiService $ozonApiService, OzPriceCalcCabinet $cabinet, array $productIds): array
    {
        $attributes = [];

        foreach (array_chunk($productIds, 999) as $chunk) {
            $payload = [
                'filter' => [
                    'product_id' => array_map('strval', $chunk),
                ],
                'limit' => 1000,
            ];

            $response = $ozonApiService->getProductAttributes($cabinet->apikey, $cabinet->client_id, $payload);

            if (! $response['success']) {
                $msg = Arr::get($response, 'data.message', 'Не удалось получить атрибуты товаров');
                Log::warning("SyncPriceCalcJob API Error: " . $msg);
                return [
                    'success' => false,
                    'message' => $msg,
                ];
            }

            $items = Arr::get($response, 'data.result', []);
            $attributes = array_merge($attributes, $items);
        }

        return [
            'success' => true,
            'items' => $attributes,
        ];
    }

    private function parseAttributes(array $attr): array
    {
        $data = [];


        $weight = (float) ($attr['weight'] ?? 0);
        $weightUnit = strtolower($attr['weight_unit'] ?? '');

        if ($weightUnit === 'g') {
            $weight = $weight / 1000;
        } elseif ($weightUnit === 'lb') {
            $weight = $weight * 0.453592;
        }

        $data['weight_kg'] = $weight;

        $dimUnit = strtolower($attr['dimension_unit'] ?? '');
        $multiplier = 1.0;

        if ($dimUnit === 'mm') {
            $multiplier = 0.1;
        } elseif ($dimUnit === 'm') {
            $multiplier = 100.0;
        }

        $data['length_cm'] = ((float) ($attr['depth'] ?? 0)) * $multiplier;
        $data['width_cm'] = ((float) ($attr['width'] ?? 0)) * $multiplier;
        $data['height_cm'] = ((float) ($attr['height'] ?? 0)) * $multiplier;

        $data['volume_liters'] = round(($data['length_cm'] * $data['width_cm'] * $data['height_cm']) / 1000, 5);

        return $data;
    }
}
