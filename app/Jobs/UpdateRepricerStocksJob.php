<?php

namespace App\Jobs;

use App\Http\Traits\WBApiTrait;
use App\Http\Traits\WBadvTrait;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerStocks;
use App\Notifications\WbCabinetAuthorizationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;

class UpdateRepricerStocksJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use WBadvTrait;
    use WBApiTrait;

    public int $delaySeconds = 1800; // 30 minutes
    public int $cabinetId;
    public int $uniqueFor = 1800; // auto-release unique lock after 30 minutes
    public ?int $subscriptionId;

    public function __construct(int $cabinetId, ?int $subscriptionId = null)
    {
        $this->onQueue('repricer_stocks');
        $this->cabinetId = $cabinetId;
        $this->subscriptionId = $subscriptionId;
    }

    public function uniqueId(): string
    {
        return 'repricer-stocks-' . $this->cabinetId;
    }

    public function handle(): void
    {
        Cache::forget(self::scheduleCacheKeyFor($this->cabinetId));

        $cabinet = RepricerCabinets::find($this->cabinetId);

        if (! $cabinet) {
            $this->releaseUniqueLock();

            return;
        }

        $stocks = RepricerStocks::where('cabinet_id', $cabinet->id)->get();

        $hasActiveStocks = $stocks->firstWhere('status', 1) !== null;

        if (! $hasActiveStocks) {
            $this->releaseUniqueLock();

            return;
        }

        if ($this->subscriptionId !== null) {
            $subscription = SubscribersSubscriptions::find($this->subscriptionId);

            if (! $subscription || (int) $subscription->status !== 1) {
                $this->deactivateCabinetStocks($cabinet->id);
                $this->releaseUniqueLock();

                return;
            }
        }

        $task = $this->parseApiResponse($this->apiCreateWarehouseRemainsReport($cabinet->apikey));
        if (! $task['success']) {
            $this->handleApiError($cabinet, $task);
            return;
        }

        $taskId = $task['data']['data']['taskId'] ?? null;

        if (! $taskId) {
            $this->handleApiError($cabinet, $task);
            return;
        }

        $attempt = 0;
        $maxAttempts = 30;

        do {
            $attempt++;
            sleep(10);

            $status = $this->parseApiResponse($this->apiGetWarehouseRemainsStatus($cabinet->apikey, $taskId));
            if (! $status['success']) {
                $this->handleApiError($cabinet, $status);
                return;
            }

            $statusValue = $status['data']['data']['status'] ?? null;

            if ($statusValue === 'done') {
                break;
            }

            if (in_array($statusValue, ['error', 'fail'], true)) {
                $this->handleApiError($cabinet, [
                    'code' => null,
                    'data' => [
                        'message' => 'Задача вернула статус ошибки',
                        'status' => $statusValue,
                    ],
                ]);
                return;
            }

            if ($attempt >= $maxAttempts) {
                $this->handleApiError($cabinet, [
                    'code' => null,
                    'data' => [
                        'message' => 'Превышен лимит ожидания статуса done',
                        'status' => $statusValue,
                    ],
                ]);
                return;
            }
        } while (true);

        $this->waitForWarehouseRemainsDownloadSlot($cabinet->id);

        $download = $this->parseApiResponse($this->apiDownloadWarehouseRemainsReport($cabinet->apikey, $taskId));
        if (! $download['success']) {
            $this->handleApiError($cabinet, $download);
            return;
        }

        $items = $download['data'] ?? [];

        if (! is_array($items)) {
            $items = [];
        }

        $stocksData = $this->buildStocksData($items);

        if (! $this->appendSellerWarehouseStocks($cabinet, $stocks, $stocksData)) {
            $this->releaseUniqueLock();

            return;
        }

        $this->updateCabinetStocks($cabinet->id, $stocksData);

        $this->clearCabinetError($cabinet);

        $this->reschedule();
    }

    private function handleApiError(RepricerCabinets $cabinet, array $response): void
    {
        $code = $response['code'] ?? null;
        $message = $this->extractErrorMessage($response);

        if (in_array($code, RepricerCabinets::FATAL_ERROR_CODES, true)) {
            $this->handleFatalCabinetError($cabinet, $code, $message);
            $this->releaseUniqueLock();

            return;
        }

        $this->markCabinetError($cabinet, $code, $message);

        $this->reschedule();
    }

    private function buildStocksData(array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            $nmId = isset($item['nmId']) ? (string) $item['nmId'] : '';

            if ($nmId === '') {
                continue;
            }

            $size = isset($item['techSize']) ? trim((string) $item['techSize']) : '';
            $quantity = 0;

            if (isset($item['warehouses']) && is_array($item['warehouses'])) {
                foreach ($item['warehouses'] as $warehouse) {
                    if (($warehouse['warehouseName'] ?? '') === 'Всего находится на складах') {
                        $quantity = (int) $warehouse['quantity'];
                        break;
                    }
                }
            }

            if (! isset($result[$nmId])) {
                $result[$nmId] = [
                    'total' => 0,
                    'sizes' => [],
                    'chrtIds' => [],
                ];
            }

            if (! isset($result[$nmId]['sizes'][$size])) {
                $result[$nmId]['sizes'][$size] = [
                    'qty' => 0,
                    'chrtId' => null,
                ];
            }

            $result[$nmId]['total'] += $quantity;
            $result[$nmId]['sizes'][$size]['qty'] += $quantity;
        }

        return $result;
    }

    private function waitForWarehouseRemainsDownloadSlot(int $cabinetId): void
    {
        $cacheKey = 'repricer:warehouse-remains-download:' . $cabinetId;

        while (true) {
            $nextAllowedTimestamp = (int) Cache::get($cacheKey, 0);
            $now = time();

            if ($now >= $nextAllowedTimestamp) {
                Cache::put($cacheKey, $now + 65, 600);
                return;
            }

            $sleepFor = max(1, min(60, $nextAllowedTimestamp - $now));
            sleep($sleepFor);
        }
    }

    private function updateCabinetStocks(int $cabinetId, array $stocksData): void
    {
        $stocks = RepricerStocks::where('cabinet_id', $cabinetId)->get();

        /** @var RepricerStocks $stock */
        foreach ($stocks as $stock) {
            $nmId = (string) $stock->nmID;
            $data = $stocksData[$nmId] ?? ['total' => 0, 'sizes' => [], 'chrtIds' => []];
            $terms = $stock->terms;
            if ((int) $stock->strategy === 1) {
                if (is_array($terms)) {
                    $terms['qty'] = $data['total'] ?? 0;
                    $terms['chrtIds'] = array_values($data['chrtIds'] ?? []);
                    if (isset($terms['barcodes'])) {
                        unset($terms['barcodes']);
                    }
                }
            } else {
                if (is_array($terms)) {
                    $sizesData = $data['sizes'] ?? [];

                    foreach ($terms as $index => $term) {
                        if (! is_array($term)) {
                            continue;
                        }

                        $sizeKey = isset($term['size']) ? trim((string) $term['size']) : '';
                        $sizeInfo = $sizesData[$sizeKey] ?? null;

                        if ($sizeInfo !== null) {
                            $term['qty'] = $sizeInfo['qty'];
                            $term['chrtId'] = $sizeInfo['chrtId'] ?? null;
                        } else {
                            $term['qty'] = $term['qty'] ?? 0;

                            if (! array_key_exists('chrtId', $term)) {
                                $term['chrtId'] = null;
                            }
                        }

                        if (array_key_exists('barcode', $term)) {
                            unset($term['barcode']);
                        }

                        $terms[$index] = $term;
                    }
                }
            }

            $stock->terms = $terms;
            $stock->save();
        }
    }

    private function appendSellerWarehouseStocks(
        RepricerCabinets $cabinet,
        EloquentCollection $stocks,
        array &$stocksData
    ): bool {
        $warehousesResponse = $this->parseApiResponse($this->apiGetSellerWarehouses($cabinet->apikey));

        if (! $warehousesResponse['success']) {
            \Log::warning('Seller warehouses fetch failed', [
                'cabinet_id' => $cabinet->id,
                'code' => $warehousesResponse['code'],
            ]);

            if ((int) $warehousesResponse['code'] === 401) {
                $this->handleFatalCabinetError($cabinet, 401, $this->extractErrorMessage($warehousesResponse));

                return false;
            }

            return true;
        }

        $warehouses = $warehousesResponse['data'] ?? [];

        if (! is_array($warehouses) || empty($warehouses)) {
            return true;
        }

        $warehouseIds = [];

        foreach ($warehouses as $warehouse) {
            $warehouseId = (int) ($warehouse['id'] ?? 0);

            if ($warehouseId > 0) {
                $warehouseIds[$warehouseId] = $warehouseId;
            }
        }

        if (empty($warehouseIds)) {
            return true;
        }

        $chrtToMatches = [];

        /** @var RepricerStocks $stock */
        foreach ($stocks as $stock) {
            $nmId = (string) $stock->nmID;
            $strategy = (int) $stock->strategy;
            $terms = $stock->terms;

            if (! isset($stocksData[$nmId])) {
                $stocksData[$nmId] = [
                    'total' => 0,
                    'sizes' => [],
                    'chrtIds' => [],
                ];
            }

            if ($strategy === 1) {
                $chrtIds = $terms['chrtIds'] ?? [];

                if (empty($chrtIds)) {
                    $fallbackChrtIds = $this->loadChrtIdsFromCard((int) $nmId);

                    if (! empty($fallbackChrtIds)) {
                        $chrtIds = $fallbackChrtIds;
                        $terms['chrtIds'] = $fallbackChrtIds;
                        $stock->terms = $terms;
                        $stock->save();
                    }
                }

                foreach ((array) $chrtIds as $chrtId) {
                    $intId = (int) $chrtId;

                    if ($intId <= 0) {
                        continue;
                    }

                    $chrtToMatches[$intId][] = ['nmId' => $nmId, 'sizeKey' => null];
                    $stocksData[$nmId]['chrtIds'][$intId] = $intId;
                }
            } else {
                if (is_array($terms)) {
                    foreach ($terms as $term) {
                        if (! is_array($term)) {
                            continue;
                        }

                        $intId = isset($term['chrtId']) ? (int) $term['chrtId'] : 0;
                        $sizeKey = isset($term['size']) ? trim((string) $term['size']) : '';

                        if ($intId <= 0) {
                            continue;
                        }

                        $chrtToMatches[$intId][] = ['nmId' => $nmId, 'sizeKey' => $sizeKey];

                        if (! isset($stocksData[$nmId]['sizes'][$sizeKey])) {
                            $stocksData[$nmId]['sizes'][$sizeKey] = [
                                'qty' => 0,
                                'chrtId' => $intId,
                            ];
                        } else {
                            $stocksData[$nmId]['sizes'][$sizeKey]['chrtId'] = $intId;
                        }

                        $stocksData[$nmId]['chrtIds'][$intId] = $intId;
                    }
                }
            }
        }

        if (empty($chrtToMatches)) {
            return true;
        }

        $allChrtIds = array_keys($chrtToMatches);

        foreach ($warehouseIds as $warehouseId) {
            $skuChunks = array_chunk($allChrtIds, 900);

            foreach ($skuChunks as $chunk) {
                if (empty($chunk)) {
                    continue;
                }

                $stocksResponse = $this->parseApiResponse(
                    $this->apiGetSellerWarehouseStocks($cabinet->apikey, $warehouseId, $chunk)
                );

                if (! $stocksResponse['success']) {
                    \Log::warning('Seller warehouse stocks request failed', [
                        'cabinet_id' => $cabinet->id,
                        'warehouse_id' => $warehouseId,
                        'code' => $stocksResponse['code'],
                    ]);

                    if ((int) $stocksResponse['code'] === 401) {
                        $this->handleFatalCabinetError($cabinet, 401, $this->extractErrorMessage($stocksResponse));

                        return false;
                    }

                    continue;
                }

                $stocksList = $stocksResponse['data']['stocks'] ?? [];

                if (! is_array($stocksList) || empty($stocksList)) {
                    continue;
                }

                $batchTotal = 0;

                foreach ($stocksList as $row) {
                    $chrtId = isset($row['chrtId']) ? (int) $row['chrtId'] : 0;
                    if ($chrtId <= 0) {
                        $chrtId = isset($row['sku']) ? (int) $row['sku'] : 0;
                    }

                    $amount = (int) ($row['amount'] ?? 0);

                    if ($chrtId <= 0 || $amount <= 0 || ! isset($chrtToMatches[$chrtId])) {
                        continue;
                    }

                    $batchTotal += $amount;

                    foreach ($chrtToMatches[$chrtId] as $match) {
                        $nmId = $match['nmId'];

                        if (! isset($stocksData[$nmId])) {
                            $stocksData[$nmId] = [
                                'total' => 0,
                                'sizes' => [],
                                'chrtIds' => [],
                            ];
                        }

                        $stocksData[$nmId]['total'] = ($stocksData[$nmId]['total'] ?? 0) + $amount;
                        $stocksData[$nmId]['chrtIds'][$chrtId] = $chrtId;

                        if ($match['sizeKey'] !== null) {
                            $sizeKey = $match['sizeKey'];

                            if (! isset($stocksData[$nmId]['sizes'][$sizeKey])) {
                                $stocksData[$nmId]['sizes'][$sizeKey] = [
                                    'qty' => 0,
                                    'chrtId' => $chrtId,
                                ];
                            }

                            $stocksData[$nmId]['sizes'][$sizeKey]['qty'] += $amount;
                            $stocksData[$nmId]['sizes'][$sizeKey]['chrtId'] = $chrtId;
                        }
                    }
                }
            }
        }
        return true;
    }

    private function loadChrtIdsFromCard(int $nmId): array
    {
        $result = [];

        try {
            $response = $this->productDataApi($nmId);

            if ($response && isset($response['sizes_table']->values) && is_array($response['sizes_table']->values)) {
                foreach ($response['sizes_table']->values as $value) {
                    if (! is_object($value)) {
                        continue;
                    }

                    $techSize = isset($value->tech_size) ? (string) $value->tech_size : '';
                    $chrtId = isset($value->chrt_id) ? (int) $value->chrt_id : 0;

                    if ($techSize === '' || $chrtId <= 0) {
                        continue;
                    }

                    $result[$techSize] = $chrtId;
                }
            }

            if (empty($result)) {
                $rawChrtIds = [];

                if (isset($response['data'])) {
                    $rawData = $response['data'];

                    if (is_object($rawData) && isset($rawData->chrt_ids)) {
                        $rawChrtIds = $rawData->chrt_ids;
                    } elseif (is_array($rawData) && isset($rawData['chrt_ids'])) {
                        $rawChrtIds = $rawData['chrt_ids'];
                    }
                } elseif (isset($response['chrt_ids'])) {
                    $rawChrtIds = $response['chrt_ids'];
                }

                foreach ((array) $rawChrtIds as $chrtId) {
                    $intId = (int) $chrtId;

                    if ($intId > 0) {
                        $result[] = $intId;
                    }
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('repricer.loadChrtIdsFromCard.failed', [
                'nm_id' => $nmId,
                'message' => $exception->getMessage(),
            ]);
        }

        return $result;
    }

    private function deactivateCabinetStocks(int $cabinetId): void
    {
        RepricerStocks::where('cabinet_id', $cabinetId)->update([
            'active' => 0,
            'status' => 0,
        ]);
    }

    private function handleFatalCabinetError(RepricerCabinets $cabinet, ?int $code, string $message): void
    {
        $cabinetId = $cabinet->id;
        $hadActiveStocks = $this->hasActiveCabinetStocks($cabinetId);

        DB::transaction(function () use ($cabinet, $cabinetId, $code, $message) {
            $cabinet->error_code = $code;
            $cabinet->error_message = $message;
            $cabinet->save();

            $this->deactivateCabinetStocks($cabinetId);
        });

        Log::warning('Репрайсер деактивирован из-за ошибки авторизации', [
            'cabinet_id' => $cabinetId,
            'error_code' => $code,
            'message' => $message,
        ]);

        if ($hadActiveStocks) {
            $this->notifyCabinetAuthorizationIssue($cabinet);
        }
    }

    private function markCabinetError(RepricerCabinets $cabinet, ?int $code, string $message): void
    {
        $cabinet->error_code = $code;
        $cabinet->error_message = $message;
        $cabinet->save();
    }

    private function clearCabinetError(RepricerCabinets $cabinet): void
    {
        if ($cabinet->error_code === null && $cabinet->error_message === null) {
            return;
        }

        $cabinet->error_code = null;
        $cabinet->error_message = null;
        $cabinet->save();
    }

    private function notifyCabinetAuthorizationIssue(RepricerCabinets $cabinet): void
    {
        try {
            $cabinet->loadMissing('user');

            $user = $cabinet->user;

            if (! $user) {
                return;
            }

            $user->notify(new WbCabinetAuthorizationNotification([
                'type' => 'repricer_stocks',
                'cabinet' => $cabinet->name,
            ]));
        } catch (\Throwable $exception) {
            \Log::warning('Не удалось отправить уведомление об ошибке авторизации репрайсера', [
                'cabinet_id' => $cabinet->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function hasActiveCabinetStocks(int $cabinetId): bool
    {
        return RepricerStocks::where('cabinet_id', $cabinetId)
            ->where('status', 1)
            ->exists();
    }

    private function reschedule(): void
    {
        $this->releaseUniqueLock();

        // Add random jitter to prevent jobs from clumping
        $delaySeconds = $this->delaySeconds + rand(1, 120);
        $scheduleKey = self::scheduleCacheKeyFor($this->cabinetId);

        if (! Cache::add($scheduleKey, true, now()->addSeconds($delaySeconds + 120))) {
            return;
        }

        self::dispatch($this->cabinetId, $this->subscriptionId)
            ->delay(now()->addSeconds($delaySeconds));
    }

    private function releaseUniqueLock(): void
    {
        $uniqueKey = sprintf('laravel_unique_job:%s:%s', static::class, $this->uniqueId());

        Cache::forget($uniqueKey);
    }

    public static function scheduleCacheKeyFor(int $cabinetId): string
    {
        return sprintf('repricer-stocks-schedule:%d', $cabinetId);
    }

    private function extractErrorMessage(array $response): string
    {
        $payload = $this->formatErrorPayload($response);

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function formatErrorPayload(array $response): array
    {
        $title = null;
        $detail = null;

        $data = $response['data'] ?? null;

        if (is_array($data)) {
            $title = isset($data['title']) ? (string) $data['title'] : null;
            $detail = isset($data['detail']) ? (string) $data['detail'] : null;

            if ($detail === null && isset($data['message'])) {
                $detail = (string) $data['message'];
            }

            if ($detail === null && isset($data['errorText'])) {
                $detail = (string) $data['errorText'];
            }
        } elseif (is_string($data)) {
            $detail = $data;
        }

        if ($title === null) {
            $title = isset($response['code']) ? 'error_' . (string) $response['code'] : 'error';
        }

        if ($detail === null) {
            $detail = 'Неизвестная ошибка API';
        }

        $normalizedDetail = mb_strtolower(trim($detail));
        $knownMap = [
            'access token expired' => 'Токен доступа истёк',
            'token scope not allowed' => 'У токена недостаточно прав',
        ];

        if (isset($knownMap[$normalizedDetail])) {
            $detail = $knownMap[$normalizedDetail];
        }

        return [
            'title' => $title,
            'detail' => $detail,
        ];
    }
}
