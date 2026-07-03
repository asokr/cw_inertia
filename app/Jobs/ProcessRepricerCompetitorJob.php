<?php

namespace App\Jobs;

use App\Http\Traits\WBApiTrait;
use App\Http\Traits\WBadvTrait;
use App\Services\Wb\WbSearchService;
use App\Models\Subscribers\Wb\Repricer\RepricerCompetitor;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerLogs;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessRepricerCompetitorJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use WBadvTrait;
    use WBApiTrait;

    public int $recordId;
    public int $uniqueFor = 600;

    private array $log = [
        'cabinet_id' => 0,
        'nmID' => 0,
        'message' => '',
        'type' => 'info',
        'strategy' => 'COMPETITORS',
    ];

    public function __construct(int $recordId)
    {
        $this->recordId = $recordId;
        $this->queue = 'repricer_competitors';
    }

    public function uniqueId(): string
    {
        return 'repricer-competitor-' . $this->recordId;
    }

    public function handle(): void
    {
        Log::info('Запуск задачи конкурентного репрайсера', ['record_id' => $this->recordId]);

        $competitor = RepricerCompetitor::with('cabinet')->find($this->recordId);

        if (! $competitor) {
            Log::warning('Номенклатура репрайсера не найдена', ['record_id' => $this->recordId]);

            return;
        }

        if ((int) $competitor->status !== 1) {
            Log::info('Запись репрайсера отключена, пропускаем задачу', [
                'record_id' => $this->recordId,
                'status' => $competitor->status,
            ]);

            return;
        }

        $cabinet = $competitor->cabinet;

        if (! $cabinet) {
            Log::warning('Не найден кабинет для номенклатуры репрайсера', [
                'record_id' => $this->recordId,
            ]);

            return;
        }

        if ($cabinet->error_code !== null && in_array($cabinet->error_code, RepricerCabinets::FATAL_ERROR_CODES, true)) {
            Log::warning('Кабинет в ошибочном состоянии, задача конкурентного репрайсера пропущена', [
                'record_id' => $this->recordId,
                'cabinet_id' => $cabinet->id,
                'error_code' => $cabinet->error_code,
            ]);

            return;
        }

        $this->log['cabinet_id'] = $cabinet->id;
        $this->log['nmID'] = (int) $competitor->nm_id;

        // Обновляем product_data
        try {
            $product = app(WbSearchService::class)->product((int) $competitor->nm_id);


            // Если вдруг API возвращает структуру { products: [...] }, извлекаем первый товар
            if (isset($product['products'][0])) {
                $product = $product['products'][0];
            }

            if (!empty($product['sizes'])) {
                $price = null;
                // Ищем цену
                foreach ($product['sizes'] as $size) {
                    if (isset($size['price']['product'])) {
                        $price = $size['price']['product'] / 100;
                        break;
                    }
                }

                $productId = data_get($product, 'id');
                $photoCount = (int) data_get($product, 'pics', 0);
                $productImage = null;

                if ($productId && $photoCount) {
                    $images = $this->getProductImages($photoCount, $productId);
                    $productImage = data_get($images, '0.imageS');
                }

                $competitor->product_data = [
                    'id' => $productId,
                    'name' => $product['name'] ?? null,
                    'brand' => $product['brand'] ?? null,
                    'supplier' => $product['supplier'] ?? null,
                    'rating' => $product['reviewRating'] ?? null,
                    'pics' => $photoCount,
                    'image' => $productImage,
                    'price' => $price,
                ];

                if ($price === null) {
                    $competitor->active = 0;
                    $competitor->status = 0;
                    $competitor->save();

                    $this->log['message'] = 'Товар отключен, так как цена на сайте не найдена (null).';
                    $this->log['type'] = 'warning';
                    $this->saveLog();

                    return;
                }

                $competitor->save();
            } else {
                // Если sizes нет - значит товар не найден или какой-то сбой
                Log::warning('RepricerJob: Не найдены размеры товара, возможно товар отсутствует', [
                    'record_id' => $competitor->id,
                    'product_data_response' => $product
                ]);

                // На всякий случай пробуем отключить, если считаем это корректным поведением для "нет товара"
                // Но пока ставим null цену в логику, чтобы сработал блок ниже если он был
                // Но раз размеров нет, цены точно нет.

                $competitor->active = 0;
                $competitor->status = 0;
                $competitor->save();

                $this->log['message'] = 'Товар отключен, так как не найдены размеры (sizes) в ответе API.';
                $this->log['type'] = 'warning';
                $this->saveLog();

                return;
            }
        } catch (\Throwable $e) {
            Log::warning('Не удалось обновить product_data в репрайсере', [
                'record_id' => $competitor->id,
                'messsage' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        if (! $this->refreshOwnBaseValues($competitor, $cabinet->apikey)) {
            if ((int) $competitor->status === 0) {
                $this->log['message'] = 'Товар не найден или отсутствует цена. Репрайсинг отключен.';
                $this->log['type'] = 'warning';
            } else {
                $this->log['message'] = 'Не удалось обновить базовую цену/скидку перед расчётом.';
                $this->log['type'] = 'error';
            }
            $this->saveLog();

            return;
        }

        $this->refreshCompetitorsData($competitor);

        $calc = $this->calculateTargetPriceFromCompetitors($competitor);

        if (! $calc['success']) {
            $this->log['message'] = $calc['messages'][0] ?? 'Не удалось рассчитать целевую цену';
            $this->log['type'] = 'error';
            $this->saveLog();

            return;
        }

        $targetDiscountedPrice = $calc['data']['target_discounted_price'];

        // --- SMART CALCULATION ONLY ---
        // Рассчитываем эффективный коэффициент только на основе данных сайта (product_data) и текущей базы.
        // Старая формула (только по скидке продавца) дает большую погрешность из-за СПП и скрытых акций.

        $currentSitePrice = data_get($competitor->product_data, 'price');
        $currentBasePrice = (float) $competitor->base_value;
        $divisor = 0.0;

        // Если нет данных для точного расчета - останавливаемся, чтобы не поставить неверную цену
        if (!$currentSitePrice || $currentBasePrice <= 0) {
            $this->log['message'] = 'Невозможно рассчитать точную цену: нет цены сайта или базовой цены. Репрайсинг остановлен.';
            $this->log['type'] = 'warning';
            $this->saveLog();
            return;
        }

        $divisor = $currentSitePrice / $currentBasePrice;

        // Sanity check: коэффициент не должен быть больше ~1 (цена сайта выше базы) или слишком маленьким
        if ($divisor > 1.1 || $divisor < 0.05) {
            $this->log['message'] = "Аномальный коэффициент цены (Site: {$currentSitePrice} / Base: {$currentBasePrice} = {$divisor}). Возможно, лаг данных WB. Пропускаем.";
            $this->log['type'] = 'warning';
            $this->saveLog();
            return;
        }

        $newBasePrice = (int) round($targetDiscountedPrice / $divisor);

        $payload = [
            'data' => [
                [
                    'nmID' => (int) $competitor->nm_id,
                    'price' => $newBasePrice,
                ],
            ],
        ];

        $logMessage = 'Устанавливаем цену по конкурентам (Smart расчет). Цель: ' . round($targetDiscountedPrice, 2) . ' р.';
        $logMessage .= ' (Коэфф. сайта: ' . round($divisor, 4) . ')';
        $logMessage .= ', новая база: ' . $newBasePrice . ' р.';

        $this->log['message'] = $logMessage;
        $this->log['type'] = 'info';
        $this->saveLog();
        // --- SMART CALCULATION END ---

        sleep(1);
        $response = $this->parseApiResponse($this->apiSetPrice($cabinet->apikey, $payload));

        if (! $response['success']) {
            $this->handleSetPriceError($competitor, $response);

            return;
        }

        $uploadId = $response['data']['data']['id'] ?? null;

        if (! $uploadId) {
            $this->handleSetPriceError($competitor, $response);

            return;
        }

        sleep(1);
        if (! $this->checkPriceChange($cabinet->apikey, $uploadId)) {
            sleep(1);

            if (! $this->checkPriceChange($cabinet->apikey, $uploadId)) {
                $this->handleSetPriceError($competitor, $response);

                return;
            }
        }

        $competitor->repeats_counter = 0;

        if ((int) $competitor->active === 0) {
            $competitor->active = 1;
        }

        $logWarning = '';


        $competitor->save();

        $this->log['message'] = 'Цена по конкурентам применена. База: ' . $newBasePrice . ' р.' . $logWarning;
        if (empty($logWarning)) {
            $this->log['type'] = 'success';
        }
        $this->saveLog();
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Задача конкурентного репрайсера завершилась с ошибкой', [
            'record_id' => $this->recordId,
            'message' => $exception->getMessage(),
        ]);

        Cache::forget(static::uniqueCacheKeyFor($this->recordId));
    }

    public static function uniqueCacheKeyFor(int $recordId): string
    {
        return sprintf('laravel_unique_job:%s:%s', self::class, 'repricer-competitor-' . $recordId);
    }

    private function calculateTargetPriceFromCompetitors(RepricerCompetitor $competitor): array
    {
        $prices = $this->extractCompetitorPrices($competitor->competitors);

        if (empty($prices)) {
            return [
                'success' => false,
                'messages' => ['Не найдены цены конкурентов в записи'],
                'data' => [],
            ];
        }

        $base = $this->aggregatePrices($prices, $competitor->competitors_price_type);

        if ($base === null) {
            return [
                'success' => false,
                'messages' => ['Не удалось агрегировать цены конкурентов'],
                'data' => [],
            ];
        }

        $target = $this->applyDifference($base, $competitor->difference, $competitor->difference_type);

        if ($target === null) {
            return [
                'success' => false,
                'messages' => ['Не удалось применить разницу к цене конкурентов'],
                'data' => [],
            ];
        }

        return [
            'success' => true,
            'messages' => [],
            'data' => [
                'base_competitors_discounted_price' => round($base, 2),
                'target_discounted_price' => round($target, 2),
            ],
        ];
    }

    private function refreshOwnBaseValues(RepricerCompetitor $competitor, string $apiKey): bool
    {
        $response = $this->parseApiResponse($this->apiGetPrices($apiKey, [
            'limit' => 1,
            'filterNmID' => (int) $competitor->nm_id,
        ]));

        if (! $response['success']) {
            return false;
        }

        $card = $response['data']['data']['listGoods'][0] ?? null;

        if (! $card) {
            $competitor->status = 0;
            $competitor->save();

            return false;
        }

        $baseValue = $card['sizes'][0]['price'] ?? null;
        $baseDiscount = $card['discount'] ?? null;

        if ($baseValue === null) {
            $competitor->status = 0;
            $competitor->save();

            return false;
        }

        if ($baseDiscount === null) {
            return false;
        }

        $updatedBase = false;

        if ((int) $competitor->active === 0 || $competitor->base_value === null) {
            $competitor->base_value = (float) $baseValue;
            $updatedBase = true;
        }

        $competitor->base_discount = (float) $baseDiscount;

        if ($updatedBase || $competitor->isDirty('base_discount')) {
            $competitor->save();
        }

        return true;
    }

    private function refreshCompetitorsData(RepricerCompetitor $competitor): void
    {
        $items = $competitor->competitors;

        if (! is_array($items) || empty($items)) {
            return;
        }

        $updated = [];

        foreach ($items as $item) {
            $nmId = null;

            if (is_array($item)) {
                $nmId = $item['nm_id'] ?? $item['nmID'] ?? $item['id'] ?? null;
            } elseif (is_numeric($item)) {
                $nmId = $item;
            }

            if (! is_numeric($nmId)) {
                continue;
            }

            $nmId = (int) $nmId;

            try {
                $nmData = app(WbSearchService::class)->product($nmId);
            } catch (\Throwable $exception) {
                Log::error('Не удалось обновить данные конкурента', [
                    'record_id' => $competitor->id,
                    'nm_id' => $nmId,
                    'message' => $exception->getMessage(),
                ]);
                continue;
            }

            if (! is_array($nmData)) {
                continue;
            }

            $product = $nmData['products'][0] ?? null;

            if (! is_array($product)) {
                continue;
            }

            $priceProduct = null;
            $sizes = $product['sizes'] ?? [];

            if (is_iterable($sizes)) {
                foreach ($sizes as $size) {
                    $price_product = $size['price']['product'] ?? null;

                    if (is_numeric($price_product)) {
                        $priceProduct = (float) $price_product / 100;
                        break;
                    }
                }
            }

            $updated[] = array_filter([
                'nm_id' => $nmId,
                'name' => $product['name'] ?? null,
                'supplier' => $product['supplier'] ?? null,
                'price' => $priceProduct,
                'rating' => $product['reviewRating'] ?? null,
                'nmFeedbacks' => $product['nmFeedbacks'] ?? null,
            ], static fn($v) => $v !== null);
        }

        if (! empty($updated)) {
            $competitor->competitors = $updated;
            $competitor->save();
        }
    }

    private function extractCompetitorPrices($competitors): array
    {
        if (! is_array($competitors)) {
            return [];
        }

        $prices = [];

        foreach ($competitors as $item) {
            if (is_array($item)) {
                $price = $item['price'] ?? null;

                if (is_numeric($price)) {
                    $prices[] = (float) $price;
                }
            }
        }

        return $prices;
    }

    private function aggregatePrices(array $prices, ?string $type): ?float
    {
        $prices = array_values(array_filter($prices, static fn($v) => is_numeric($v)));

        if (empty($prices)) {
            return null;
        }

        $type = $type ?: 'min';

        return match ($type) {
            'max' => (float) max($prices),
            'average' => array_sum($prices) / count($prices),
            default => (float) min($prices),
        };
    }

    private function applyDifference(float $basePrice, $difference, $differenceType): ?float
    {
        $diff = is_numeric($difference) ? (float) $difference : 0.0;

        if ($diff == 0.0) {
            return $basePrice;
        }

        if (! is_string($differenceType) || $differenceType === '') {
            return null;
        }

        $price = match ($differenceType) {
            'amount' => $basePrice - $diff,
            'percent' => $basePrice * (1 - ($diff / 100)),
            default => null,
        };

        if ($price === null) {
            return null;
        }

        return max(0.01, $price);
    }

    private function checkPriceChange(string $apiKey, int $uploadId): bool
    {
        $response = $this->parseApiResponse($this->apiGetPriceChangeStatus($apiKey, $uploadId));

        if (! $response['success']) {
            return false;
        }

        $status = $response['data']['data']['status'] ?? null;

        return $status === 3;
    }

    private function handleSetPriceError(RepricerCompetitor $competitor, array $response): void
    {
        $message = 'Не удалось сменить стоимость. Код: ' . ($response['code'] ?? '');
        $this->log['message'] = $message;
        $this->log['type'] = 'error';
        $this->saveLog();

        if (($response['code'] ?? null) === 401) {
            RepricerSettings::where(['cabinet_id' => $this->log['cabinet_id']])->update(['status' => 0]);
            $this->log['message'] = 'Не верный ключ API. Номенклатуры кабинета отключены. Проверьте API ключ.';
            $this->log['type'] = 'error';
            $this->saveLog();
        }

        $competitor->repeats_counter++;
        $competitor->save();
    }

    private function saveLog(): void
    {
        if (empty($this->log['cabinet_id']) || empty($this->log['nmID'])) {
            return;
        }

        RepricerLogs::create($this->log);
    }
}
