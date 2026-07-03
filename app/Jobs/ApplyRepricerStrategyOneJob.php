<?php

namespace App\Jobs;

use App\Http\Traits\WBadvTrait;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use App\Models\Subscribers\Wb\Repricer\RepricerLogs;
use App\Models\Subscribers\Wb\Repricer\RepricerSettings;
use App\Models\Subscribers\Wb\Repricer\RepricerStocks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ApplyRepricerStrategyOneJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use WBadvTrait;

    public int $stockId;
    public int $uniqueFor = 1800;

    private array $log = [
        'cabinet_id' => 0,
        'nmID' => 0,
        'message' => '',
        'type' => 'info',
        'strategy' => 'STOCKS',
    ];

    private int $errorsLimit = 10;
    private ?string $apiKey = null;
    private const PRICE_ALREADY_SET_LIMIT = 3;
    private bool $priceAlreadySetTriggeredDeactivation = false;

    public function __construct(int $stockId)
    {
        $this->onQueue('repricer_stocks');
        $this->stockId = $stockId;
    }

    public function uniqueId(): string
    {
        return 'repricer-price-' . $this->stockId;
    }

    public function handle(): void
    {
        $stock = RepricerStocks::with('cabinet')->find($this->stockId);

        Cache::put($this->dispatchLockKey(), true, now()->addMinutes(40));

        if (! $stock) {
            $this->releaseUniqueLock();

            return;
        }

        if ((int) $stock->status !== 1) {
            $this->releaseUniqueLock();

            return;
        }

        $strategy = (int) $stock->strategy;

        if (! in_array($strategy, [1, 2], true)) {
            $this->releaseUniqueLock();

            return;
        }

        $cabinet = $stock->cabinet;

        if (! $cabinet instanceof RepricerCabinets) {
            $this->releaseUniqueLock();

            return;
        }

        $this->apiKey = $cabinet->apikey;

        if (! $this->apiKey) {
            $this->releaseUniqueLock();

            return;
        }

        $this->log['cabinet_id'] = $cabinet->id;
        $this->log['nmID'] = (int) $stock->nmID;


        if ($stock->base_value === null || $stock->base_discount === null) {
            if (! $this->initializeBaseValues($stock)) {
                $this->reschedule();

                return;
            }

            $stock->refresh();
        }

        $addValue = $this->determineAddValue($stock, $strategy);

        if ($addValue === null) {
            $this->removeFromStrategy($stock);
            $this->reschedule();

            return;
        }

        if ($stock->added_value !== null && abs($stock->added_value - $addValue) < 0.01 && (int) $stock->active === 1) {
            $stock->repeats_counter = 0;
            $stock->save();
            $this->reschedule();

            return;
        }

        if ($this->applyPriceChange($stock, $addValue)) {
            $stock->active = 1;
            $stock->added_value = $addValue;
            $stock->repeats_counter = 0;
            $stock->save();
            $this->reschedule();

            return;
        }

        $stock->repeats_counter++;

        if ($this->priceAlreadySetTriggeredDeactivation) {
            $stock->repeats_counter = 0;
            $stock->save();
            $this->releaseUniqueLock();

            return;
        }

        if ($stock->repeats_counter >= $this->errorsLimit) {
            $stock->active = 0;
            $stock->status = 0;
            $stock->added_value = 0;
            $stock->save();

            $this->writeLog('Останавливаем работу с номенклатурой после повторяющихся ошибок. Проверьте настройки.', 'error');
            $this->releaseUniqueLock();

            return;
        }

        $stock->save();
        $this->reschedule();
    }

    private function determineAddValue(RepricerStocks $stock, int $strategy): ?float
    {
        return match ($strategy) {
            1 => $this->determineAddValueStrategyOne($stock),
            2 => $this->determineAddValueStrategyTwo($stock),
            default => null,
        };
    }

    private function determineAddValueStrategyOne(RepricerStocks $stock): ?float
    {
        $terms = $stock->terms;

        if (! is_array($terms)) {
            return null;
        }

        $currentQty = (int) ($terms['qty'] ?? 0);
        $rules = $terms['data'] ?? [];

        if (! is_array($rules) || empty($rules)) {
            return null;
        }

        $normalizedRules = [];

        foreach ($rules as $rule) {
            if (! isset($rule['from'], $rule['add_to_price'])) {
                continue;
            }

            $normalizedRules[] = [
                'from' => (int) $rule['from'],
                'is_percent' => (int) ($rule['is_procent'] ?? 0) === 1,
                'add_to_price' => (float) $rule['add_to_price'],
            ];
        }

        if (empty($normalizedRules)) {
            return null;
        }

        usort($normalizedRules, static fn($a, $b) => $a['from'] <=> $b['from']);

        $matchedRule = null;

        foreach ($normalizedRules as $rule) {
            if ($currentQty <= $rule['from']) {
                $matchedRule = $rule;
                break;
            }
        }

        if (! $matchedRule) {
            return null;
        }

        $baseValue = (float) ($stock->base_value ?? 0);

        if ($matchedRule['is_percent']) {
            $calc = round($baseValue * $matchedRule['add_to_price'] / 100, 2);
            return $calc;
        }

        $calc = round($matchedRule['add_to_price'], 2);
        return $calc;
    }

    private function determineAddValueStrategyTwo(RepricerStocks $stock): ?float
    {
        $terms = $stock->terms;

        if (! is_array($terms)) {
            return null;
        }

        $baseValue = (float) ($stock->base_value ?? 0);
        $maxAddValue = null;
        $matchedRules = [];

        foreach ($terms as $term) {
            $qty = (int) ($term['qty'] ?? 0);
            $values = $term['values'] ?? [];

            if (! is_array($values) || empty($values)) {
                continue;
            }

            $matchedRule = null;

            foreach ($values as $value) {
                if (! isset($value['from'], $value['add_to_price'])) {
                    continue;
                }

                $threshold = (int) $value['from'];

                if ($qty > $threshold) {
                    continue;
                }

                $rule = [
                    'from' => $threshold,
                    'is_percent' => (int) ($value['is_procent'] ?? 0) === 1,
                    'add_to_price' => (float) $value['add_to_price'],
                ];

                if ($matchedRule === null || $threshold < $matchedRule['from']) {
                    $matchedRule = $rule;
                }
            }

            if (! $matchedRule) {
                continue;
            }

            $value = $matchedRule['is_percent']
                ? $baseValue * $matchedRule['add_to_price'] / 100
                : $matchedRule['add_to_price'];

            $matchedRules[] = [
                'size' => $term['size'] ?? null,
                'qty' => $qty,
                'rule' => $matchedRule,
                'calculated' => $value,
            ];

            if ($maxAddValue === null || $value > $maxAddValue) {
                $maxAddValue = $value;
            }
        }

        if ($maxAddValue === null) {
            return null;
        }

        return round($maxAddValue, 2);
    }

    private function applyPriceChange(RepricerStocks $stock, float $addValue): bool
    {
        $baseValue = (float) $stock->base_value;
        $newPrice = (int) round($baseValue + $addValue);

        $payload = [
            'data' => [
                [
                    'nmID' => (int) $stock->nmID,
                    'price' => $newPrice,
                ],
            ],
        ];

        sleep(1);
        $response = $this->parseApiResponse($this->apiSetPrice($this->apiKey, $payload));

        if (! $response['success']) {
            $this->handleSetPriceError($stock, $response);

            return false;
        }

        $uploadId = $response['data']['data']['id'] ?? null;

        if (! $uploadId) {
            $this->handleSetPriceError($stock, $response);

            return false;
        }

        sleep(1);
        if (! $this->checkPriceChange($stock, $uploadId)) {
            sleep(1);

            if (! $this->checkPriceChange($stock, $uploadId)) {
                $this->handleSetPriceError($stock, $response);

                return false;
            }
        }

        $this->resetPriceAlreadySetCounter($stock->id);
        $this->writeLog('По условиям репрайсера зашли в стратегию. Новая цена ' . $newPrice . ' р.', 'success');

        return true;
    }

    private function checkPriceChange(RepricerStocks $stock, int $uploadId): bool
    {
        sleep(1);
        $response = $this->parseApiResponse($this->apiGetPriceChangeStatus($this->apiKey, $uploadId));

        if (! $response['success']) {
            $this->log['message'] = 'Не удалось подтвердить изменение стоимости. Код: ' . $response['code'];
            $this->log['type'] = 'error';
            $this->saveLog();

            return false;
        }

        $status = $response['data']['data']['status'] ?? null;

        if ($status === 3) {
            return true;
        }

        if ($status === 4) {
            $message = 'отменена';
        } elseif ($status === 5) {
            $message = 'обработана, но в товарах есть ошибки';
        } elseif ($status === 6) {
            $message = 'обработана, но во всех товарах есть ошибки';
        } else {
            $message = 'статус: ' . ($status ?? 'неизвестен');
        }

        $this->log['message'] = 'Ошибка в товаре. Текст ответа WB: ' . $message;
        $this->log['type'] = 'error';
        $this->saveLog();

        $this->deactivateIfPriceEqualsBase($stock, 'после ошибки проверки статуса изменения цены');

        return false;
    }

    private function handleSetPriceError(RepricerStocks $stock, array $response): void
    {
        $message = 'Не удалось сменить стоимость. Код: ' . $response['code'];
        $data = $response['data'] ?? null;

        if (is_array($data) && isset($data['errorText'])) {
            $message .= '. ' . $data['errorText'];
        }

        if ($this->isPriceAlreadySetError($response)) {
            $count = $this->incrementPriceAlreadySetCounter($stock->id);

            if ($count >= self::PRICE_ALREADY_SET_LIMIT) {
                $this->deactivateStockDueToPriceAlreadySet($stock);
                $this->priceAlreadySetTriggeredDeactivation = true;
                $this->resetPriceAlreadySetCounter($stock->id);

                $this->writeLog('Мы трижды получили ошибку от WB, что цена уже установлена. Отключаем работу номенклатуры.', 'warning');

                return;
            }

            $this->writeLog($message . ' (повтор ' . $count . ' из ' . self::PRICE_ALREADY_SET_LIMIT . ')', 'warning');

            return;
        }

        $this->resetPriceAlreadySetCounter($stock->id);

        $this->writeLog($message, 'error');

        $this->deactivateIfPriceEqualsBase($stock, 'после ошибки смены стоимости');

        if ($response['code'] === 401) {
            RepricerSettings::where(['cabinet_id' => $this->log['cabinet_id']])->update(['status' => 0]);
            $this->writeLog('Не верный ключ API. Номенклатуры кабинета отключены. Проверьте API ключ.', 'error');
        }

        if ($stock->repeats_counter >= $this->errorsLimit) {
            $stock->active = 0;
            $stock->status = 0;
        }
    }

    private function initializeBaseValues(RepricerStocks $stock): bool
    {
        $params = [
            'limit' => 1,
            'filterNmID' => (int) $stock->nmID,
        ];

        $response = $this->parseApiResponse($this->apiGetPrices($this->apiKey, $params));

        if (! $response['success']) {
            $this->writeLog('Не удалось получить текущие цены и скидки. Код: ' . $response['code'], 'error');

            return false;
        }

        $card = $response['data']['data']['listGoods'][0] ?? null;

        if (! $card) {
            $this->writeLog('Не удалось получить карточку товара при инициализации базовых значений.', 'error');

            return false;
        }

        $values = [
            'base_value' => $card['sizes'][0]['price'] ?? null,
            'base_discount' => $card['discount'] ?? null,
        ];

        if ($values['base_value'] === null || $values['base_discount'] === null) {
            $this->writeLog('Получены некорректные данные о базовой цене/скидке.', 'error');

            return false;
        }

        $stock->base_value = (float) $values['base_value'];
        $stock->base_discount = (float) $values['base_discount'];
        $stock->save();

        RepricerStocks::where('nmID', $stock->nmID)->update($values);

        return true;
    }

    private function removeFromStrategy(RepricerStocks $stock): void
    {
        if (! $stock->active) {
            return;
        }

        $payload = [
            'data' => [
                [
                    'nmID' => (int) $stock->nmID,
                    'price' => (int) $stock->base_value,
                ],
            ],
        ];

        sleep(1);
        $response = $this->parseApiResponse($this->apiSetPrice($this->apiKey, $payload));

        if (! $response['success']) {
            $this->writeLog('Не удалось вернуть цену к базовой. Код: ' . $response['code'], 'error');
            $this->deactivateIfPriceEqualsBase($stock, 'после ошибки возврата базовой стоимости');

            return;
        }

        $uploadId = $response['data']['data']['id'] ?? null;

        if ($uploadId && $this->checkPriceChange($stock, $uploadId)) {
            $stock->active = 0;
            $stock->added_value = 0;
            $stock->repeats_counter = 0;
            $stock->save();
            $this->resetPriceAlreadySetCounter($stock->id);

            $this->writeLog('Выход из стратегии. Стоимость товара до скидки - ' . $this->formatValue($stock->base_value) . ' р.', 'success');
        } elseif ($uploadId === null) {
            $this->deactivateIfPriceEqualsBase($stock, 'после проверки статуса возврата базовой стоимости');
        }
    }

    private function deactivateIfPriceEqualsBase(RepricerStocks $stock, string $context): void
    {
        if ((int) $stock->active === 0 || $stock->base_value === null) {
            return;
        }

        $isBase = $this->isCurrentPriceEqualToBase($stock);

        if ($isBase === true) {
            $stock->active = 0;
            $stock->added_value = 0;
            $stock->repeats_counter = 0;
            $stock->save();

            $this->writeLog('Цена на Wildberries равна базовой, отключаем стратегию (' . $context . ').', 'info');
        }
    }

    private function isCurrentPriceEqualToBase(RepricerStocks $stock): ?bool
    {
        $currentPrice = $this->fetchCurrentBasePrice($stock);

        if ($currentPrice === null) {
            return null;
        }

        return abs($currentPrice - (float) $stock->base_value) < 0.01;
    }

    private function fetchCurrentBasePrice(RepricerStocks $stock): ?float
    {
        $params = [
            'limit' => 1,
            'filterNmID' => (int) $stock->nmID,
        ];

        $response = $this->parseApiResponse($this->apiGetPrices($this->apiKey, $params));

        if (! $response['success']) {
            return null;
        }

        $card = $response['data']['data']['listGoods'][0] ?? null;

        if (! $card) {
            return null;
        }

        $price = $card['sizes'][0]['price'] ?? null;

        if ($price === null) {
            return null;
        }

        return (float) $price;
    }

    private function writeLog(string $message, string $type = 'info'): void
    {
        $this->log['message'] = $message;
        $this->log['type'] = $type;
        $this->saveLog();
    }

    private function saveLog(): void
    {
        if (empty($this->log['cabinet_id']) || empty($this->log['nmID'])) {
            return;
        }

        RepricerLogs::create($this->log);
    }

    private function formatValue($value): string
    {
        return number_format((float) $value, 0, '.', ' ');
    }

    private function reschedule(): void
    {
        $this->releaseUniqueLock();

        Cache::put($this->dispatchLockKey(), true, now()->addMinutes(40));

        static::dispatch($this->stockId)->delay(now()->addMinutes(30));
    }

    private function releaseUniqueLock(): void
    {
        $uniqueKey = sprintf('laravel_unique_job:%s:%s', static::class, $this->uniqueId());


        Cache::forget($uniqueKey);
        Cache::forget($this->dispatchLockKey());
    }

    public function failed(): void
    {
        $this->releaseUniqueLock();
    }

    private function dispatchLockKey(): string
    {
        return 'repricer-price-dispatch-' . $this->stockId;
    }

    private function priceAlreadySetCacheKey(int $stockId): string
    {
        return 'repricer-price-already-set:' . $stockId;
    }

    private function incrementPriceAlreadySetCounter(int $stockId): int
    {
        $key = $this->priceAlreadySetCacheKey($stockId);
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, now()->addHours(24));

        return $count;
    }

    private function resetPriceAlreadySetCounter(int $stockId): void
    {
        Cache::forget($this->priceAlreadySetCacheKey($stockId));
    }

    private function deactivateStockDueToPriceAlreadySet(RepricerStocks $stock): void
    {
        $stock->active = 0;
        $stock->status = 0;
        $stock->added_value = 0;
        $stock->repeats_counter = 0;
        $stock->save();
    }

    private function isPriceAlreadySetError(array $response): bool
    {
        if (($response['code'] ?? null) !== 400) {
            return false;
        }

        $data = $response['data'] ?? null;

        if (is_array($data)) {
            $errorText = $data['errorText'] ?? $data['message'] ?? null;

            if (is_string($errorText)) {
                return stripos($errorText, 'already set') !== false;
            }
        } elseif (is_string($data)) {
            return stripos($data, 'already set') !== false;
        }

        return false;
    }
}
