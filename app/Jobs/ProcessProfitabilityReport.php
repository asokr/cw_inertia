<?php

namespace App\Jobs;

use App\Models\JobStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;
use App\Services\Wb\ProfitabilityApiService;
use App\Models\Subscribers\Wb\Profitability\Item;
use App\Models\Subscribers\Wb\Profitability\Report;
use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV3Data;
use App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationCabinets;
use App\Notifications\WbCabinetAuthorizationNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class ProcessProfitabilityReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 минут на случай больших отчётов
    public int $tries = 1;
    public ?int $statusRecordId = null;

    public function __construct(
        public int $cabinetId,
        public string $dateFrom,
        public string $dateTo,
        public int $userId,
        public float $dopRashod = 0.0,
        public float $nalogPercent = 0.0,
    ) {}

    public function handle(ProfitabilityApiService $apiService): void
    {
        if ($this->failIfAlreadyProcessing()) {
            Log::warning('[ProfitabilityReport] Джоба отклонена — уже выполняется', [
                'cabinet_id' => $this->cabinetId,
            ]);
            return;
        }

        $this->statusRecordId = $this->upsertStatusRecord();
        $this->updateStatusProgress('preparing', ['waiting_for_api' => false]);

        try {
            $cabinet = ProfitabilityCabinet::findOrFail($this->cabinetId);

            $operations = [
                'logistics'            => 'Логистика',
                'returns'              => 'Возврат',
                'sales'                => 'Продажа',
                'storage'              => 'Хранение',
                'penalty'              => 'Штраф',
                'acceptance'           => 'Платная приемка',
                'withholdings'         => 'Удержание',
                'logistics_correction' => 'Коррекция логистики',
                'sales_correction'     => 'Коррекция продаж',
                'cashback'             => 'Добровольная компенсация при возврате'
            ];

            $operationKeys = array_flip($operations);

            $totals = [
                'sales_quantity' => 0,
                'sales_amount' => 0,
                'returns_quantity' => 0,
                'returns_amount' => 0,
                'percent_buy' => 0,
                'penalties' => 0,
                'deduction' => 0,
                'storage_fee' => 0,
                'acceptance' => 0,
                'cashback' => 0,
                'nalog' => 0,
                'sales_correction' => 0,
                'logistics' => 0,
            ];

            $dopRashodTotal = max((float) $this->dopRashod, 0);
            $nalogPercent = min(max((float) $this->nalogPercent, 0), 100);

            $logisticsSaleBonusCount = 0;
            $logisticsRelevantCount = 0;
            $logisticsSum1 = 0;
            $logisticsSum2 = 0;

            $priceCalcCabinets = PriceCalculationCabinets::where('user_id', $this->userId)
                ->get(['id', 'name', 'apikey']);

            $priceCalcCabinetIds = $this->resolveRelevantPriceCalcCabinetIds($cabinet, $priceCalcCabinets->all());
            $costLookup = $this->buildPriceCalculationCostLookup($priceCalcCabinetIds);

            $totals['margin'] = 0;
            $totals['purchase_cost'] = 0;

            $itemsData = [];

            try {
                $dateFromIso = Carbon::parse($this->dateFrom)->startOfDay()->toDateString();
                $dateToIso = Carbon::parse($this->dateTo)->endOfDay()->toDateString();
            } catch (\Exception $e) {
                Log::error('[ProfitabilityReport] Ошибка парсинга даты, используются сырые данные', [
                    'date_from' => $this->dateFrom,
                    'date_to' => $this->dateTo,
                    'error' => $e->getMessage()
                ]);
                // Fallback to previous method if Carbon fails
                $dateFromIso = date('Y-m-d', strtotime($this->dateFrom));
                $dateToIso = date('Y-m-d', strtotime($this->dateTo));
            }

            $rrdId = 0;
            $batch = 0;
            $rowsLoaded = 0;

            while (true) {
                $batch++;
                $this->updateStatusProgress('fetching', [
                    'batch' => $batch,
                    'rows_loaded' => $rowsLoaded,
                    'waiting_for_api' => false,
                ]);

                $payload = $apiService->getReportDetailByPeriod(
                    $dateFromIso,
                    $dateToIso,
                    $cabinet,
                    $rrdId,
                    [],
                    false
                );

                if (! ($payload['success'] ?? false)) {
                    $code = $payload['code'] ?? null;
                    $rawData = $payload['data'] ?? null;

                    Log::error('[ProfitabilityReport] API вернул ошибку', [
                        'code' => $code,
                        'success' => $payload['success'] ?? null,
                        'data_preview' => is_string($rawData) ? mb_substr($rawData, 0, 500) : $rawData,
                    ]);

                    if ($code === 401 && $this->isAccessTokenExpired($rawData)) {
                        $userFriendlyError = 'Срок действия API токена Wildberries истёк. Обновите токен кабинета и повторите запрос.';

                        $this->updateStatusFailed($userFriendlyError);
                        $this->notifyTokenExpired($cabinet);

                        return;
                    }

                    throw new \RuntimeException($this->formatApiError($rawData, $code));
                }

                $rows = $payload['data'] ?? [];

                if (empty($rows)) {
                    break;
                }

                $rowsLoaded += count($rows);
                $this->updateStatusProgress('fetching', [
                    'batch' => $batch,
                    'rows_loaded' => $rowsLoaded,
                    'waiting_for_api' => false,
                ]);

                foreach ($rows as $row) {
                    $operationName = $this->rowValue($row, 'sellerOperName', 'supplier_oper_name');
                    $operationKey = $operationName && isset($operationKeys[$operationName])
                        ? $operationKeys[$operationName]
                        : null;

                    $amount = 0;
                    $logisticsCost = 0;
                    $quantity = (int) $this->rowValue($row, 'quantity', 'quantity', 0);
                    $retailAmount = (float) $this->rowValue($row, 'retailAmount', 'retail_amount', 0);
                    $cashback = (float) $this->rowValue($row, 'cashbackAmount', 'cashback_amount', 0)
                        + (float) $this->rowValue($row, 'cashbackDiscount', 'cashback_discount', 0);
                    $nalogAmount = 0;

                    switch ($operationKey) {
                        case 'sales':
                            $amount = (float) $this->rowValue($row, 'forPay', 'ppvz_for_pay', 0);
                            $nalogAmount = $retailAmount > 0 ? $retailAmount * $nalogPercent / 100 : 0;
                            $totals['sales_quantity'] += $quantity;
                            $totals['sales_amount'] += $amount;
                            $totals['cashback'] += $cashback;
                            $totals['nalog'] += $nalogAmount;
                            break;
                        case 'returns':
                            $amount = (float) $this->rowValue($row, 'forPay', 'ppvz_for_pay', 0);
                            $totals['returns_quantity'] += $quantity;
                            $totals['returns_amount'] += $amount;
                            break;
                        case 'logistics':
                            $amount = (float) $this->rowValue($row, 'deliveryService', 'delivery_rub', 0);
                            $logisticsCost = $amount;
                            $logisticsSum1 += $amount;
                            $bonusType = $this->rowValue($row, 'bonusTypeName', 'bonus_type_name');
                            if ($bonusType === 'К клиенту при продаже') {
                                $logisticsSaleBonusCount++;
                            }
                            if (in_array($bonusType, ['От клиента при возврате', 'От клиента при отмене', 'К клиенту при продаже'], true)) {
                                $logisticsRelevantCount++;
                            }
                            break;
                        case 'logistics_correction':
                            $amount = (float) $this->rowValue($row, 'deliveryService', 'delivery_rub', 0);
                            $logisticsCost = $amount;
                            $logisticsSum2 += $amount;
                            break;
                        case 'acceptance':
                            $amount = (float) $this->rowValue($row, 'paidAcceptance', 'acceptance', 0);
                            $totals['acceptance'] += $amount;
                            break;
                        case 'penalty':
                            $amount = (float) $this->rowValue($row, 'penalty', 'penalty', 0);
                            $totals['penalties'] += $amount;
                            break;
                        case 'withholdings':
                            $amount = (float) $this->rowValue($row, 'deduction', 'deduction', 0);
                            $totals['deduction'] += $amount;
                            break;
                        case 'storage':
                            $amount = (float) $this->rowValue($row, 'paidStorage', 'storage_fee', 0);
                            $totals['storage_fee'] += $amount;
                            break;
                        case 'sales_correction':
                            $value = (float) $this->rowValue($row, 'forPay', 'ppvz_for_pay', 0);
                            if ($this->rowValue($row, 'docTypeName', 'doc_type_name', '') === 'Возврат') {
                                $value *= -1;
                            }
                            $totals['sales_correction'] += $value;
                            $amount = 0;
                            break;
                        default:
                            $amount = 0;
                            break;
                    }

                    $purchaseCost = 0;
                    $profitMargin = 0;

                    $itemsData[] = new Item([
                        'nm_id'                 => $this->rowValue($row, 'nmId', 'nm_id'),
                        'sa_name'               => $this->rowValue($row, 'vendorCode', 'sa_name'),
                        'supplier_oper_name'    => $operationName,
                        'reasoning'             => $this->rowValue($row, 'bonusTypeName', 'bonus_type_name'),
                        'size'                  => $this->rowValue($row, 'techSize', 'ts_name'),
                        'barcode'               => $this->rowValue($row, 'sku', 'barcode'),
                        'warehouse'             => $this->rowValue($row, 'officeName', 'office_name'),
                        'quantity'              => $quantity,
                        'sum_to_transfer'       => $amount,
                        'purchase_cost'         => (float) ($purchaseCost ?? 0),
                        'logistics'             => $logisticsCost,
                        'cost_adjustments'      => 0,
                        'dop_rashod'            => 0,
                        'cashback'              => $cashback,
                        'nalog'                 => $nalogAmount,
                        'margin'                => $profitMargin,
                        'profitability_percent' => 0,
                    ]);
                }

                $nextRrdId = $payload['next_rrdid'] ?? null;

                if (! $nextRrdId) {
                    break;
                }

                $rrdId = $nextRrdId;
                $this->updateStatusProgress('fetching', [
                    'batch' => $batch,
                    'rows_loaded' => $rowsLoaded,
                    'waiting_for_api' => true,
                ]);
                sleep(65);
            }

            $this->updateStatusProgress('analyzing', ['waiting_for_api' => false]);

            if ($logisticsRelevantCount > 0) {
                if ($logisticsSaleBonusCount === 0) {
                    $logisticsSaleBonusCount = 1;
                }
                $totals['percent_buy'] = $this->safeDivide($logisticsSaleBonusCount, $logisticsRelevantCount) * 100;
            } else {
                $totals['percent_buy'] = 0;
            }

            $totals['sales_amount'] = round($totals['sales_amount'], 2);
            $totals['returns_amount'] = round($totals['returns_amount'], 2);
            $totals['penalties'] = round($totals['penalties'], 2);
            $totals['deduction'] = round($totals['deduction'], 2);
            $totals['storage_fee'] = round($totals['storage_fee'], 2);
            $totals['acceptance'] = round($totals['acceptance'], 2);
            $totals['cashback'] = round($totals['cashback'], 2);
            $totals['nalog'] = round($totals['nalog'], 2);
            $totals['sales_correction'] = round($totals['sales_correction'], 2);
            $totals['logistics'] = round($logisticsSum1 + $logisticsSum2, 2);

            $this->applyPurchaseCostsAndBaseMargins($itemsData, $totals, $operations, $costLookup);

            $logisticsSumByNmId = [];
            $salesCountByNmId = [];
            foreach ($itemsData as $item) {
                if (!$item->nm_id) {
                    continue;
                }

                if (in_array($item->supplier_oper_name, [
                    $operations['logistics'],
                    $operations['logistics_correction'],
                ], true)) {
                    $logisticsSumByNmId[$item->nm_id] = ($logisticsSumByNmId[$item->nm_id] ?? 0) + $item->logistics;
                }

                if ($item->supplier_oper_name === $operations['sales']) {
                    $salesCountByNmId[$item->nm_id] = ($salesCountByNmId[$item->nm_id] ?? 0) + 1;
                }
            }

            $logisticsByNmId = [];
            foreach ($logisticsSumByNmId as $nmId => $sum) {
                $count = $salesCountByNmId[$nmId] ?? 0;
                if ($count > 0) {
                    $logisticsByNmId[$nmId] = $this->safeDivide($sum, $count);
                }
            }

            foreach ($itemsData as $item) {
                if (
                    $item->nm_id &&
                    $item->supplier_oper_name === $operations['sales'] &&
                    isset($logisticsByNmId[$item->nm_id])
                ) {
                    $item->logistics = $logisticsByNmId[$item->nm_id];
                }
            }

            $penaltiesByBarcode = [];
            $storageTotal = 0;
            $salesItemsCount = 0;
            foreach ($itemsData as $item) {
                if ($item->supplier_oper_name === $operations['penalty'] && $item->barcode) {
                    $penaltiesByBarcode[$item->barcode] = ($penaltiesByBarcode[$item->barcode] ?? 0) + $item->sum_to_transfer;
                }

                if ($item->supplier_oper_name === $operations['storage']) {
                    $storageTotal += $item->sum_to_transfer;
                }

                if ($item->supplier_oper_name === $operations['sales']) {
                    $salesItemsCount++;
                }
            }

            $salesTransferTotal = 0;

            foreach ($itemsData as $item) {
                if ($item->supplier_oper_name !== $operations['sales']) {
                    continue;
                }

                $salesTransferTotal += max((float) ($item->sum_to_transfer ?? 0), 0);
            }

            foreach ($itemsData as $item) {
                if ($item->supplier_oper_name === $operations['sales']) {
                    $revenue = (float) ($item->sum_to_transfer ?? 0);
                    $storageShare = 0;

                    if ($salesTransferTotal > 0 && $revenue > 0) {
                        $storageShare = $storageTotal * $this->safeDivide($revenue, $salesTransferTotal);
                    }

                    $item->cost_adjustments = ($penaltiesByBarcode[$item->barcode] ?? 0) + $storageShare;
                }
            }

            $this->updateStatusProgress('calculating', ['waiting_for_api' => false]);

            foreach ($itemsData as $item) {
                if ($item->supplier_oper_name === $operations['sales']) {
                    $revenue = $item->sum_to_transfer;
                    $dopRashodShare = 0;

                    if ($salesTransferTotal > 0 && $revenue > 0) {
                        $dopRashodShare = $dopRashodTotal * $this->safeDivide((float) $revenue, $salesTransferTotal);
                    }

                    $item->dop_rashod = $dopRashodShare;

                    $item->margin = $item->margin
                        - $item->logistics
                        - $item->cost_adjustments
                        - (float) ($item->cashback ?? 0)
                        - (float) ($item->nalog ?? 0)
                        - $dopRashodShare;
                    $item->profitability_percent = $revenue > 0
                        ? round($this->safeDivide($item->margin, $revenue) * 100, 2)
                        : 0;
                }
            }

            $this->updateStatusProgress('saving', ['waiting_for_api' => false]);

            DB::transaction(function () use ($cabinet, &$totals, $itemsData, $dopRashodTotal, $nalogPercent) {
                $report = Report::updateOrCreate(
                    [
                        'cabinet_id' => $cabinet->id,
                    ],
                    [
                        'date_from'  => $this->dateFrom,
                        'date_to'    => $this->dateTo,
                        ...$totals,
                    ]
                );

                $report->items()->delete();

                $report->items()->saveMany($itemsData);

                $revenue = $totals['sales_amount'];
                $salesCorrection = $totals['sales_correction'] ?? 0;
                $costs   = ($totals['returns_amount'] ?? 0)
                    + ($totals['penalties'] ?? 0)
                    + ($totals['logistics'] ?? 0)
                    + ($totals['deduction'] ?? 0)
                    + ($totals['storage_fee'] ?? 0)
                    + ($totals['cashback'] ?? 0)
                    + ($totals['nalog'] ?? 0)
                    + $dopRashodTotal
                    - ($salesCorrection > 0 ? $salesCorrection : 0)
                    + ($salesCorrection < 0 ? abs($salesCorrection) : 0)
                    + ($totals['acceptance'] ?? 0);

                $total  = $revenue - $costs;
                $profit  = $total - ($totals['purchase_cost'] ?? 0);

                $profitabilityPercent = ($totals['purchase_cost'] ?? 0) > 0
                    ? round($this->safeDivide($profit, $totals['purchase_cost']) * 100, 2)
                    : 0;

                $report->update([
                    'purchase_cost'     => $totals['purchase_cost'],
                    'margin'     => $profit,
                    'cashback' => $totals['cashback'],
                    'dop_rashod' => $dopRashodTotal,
                    'nalog' => $totals['nalog'],
                    'nalog_percent' => $nalogPercent,
                    'correction_sales' => $totals['sales_correction'],
                    'total_profitability' => $profitabilityPercent,
                    'itog' => $total,
                ]);
            });

            Cache::forget("profitability_widget_{$cabinet->id}");
            $this->forgetProfitabilityReportCache($cabinet->id);

            JobStatus::where('id', $this->statusRecordId)->update([
                'status' => 'done',
                'error' => null,
                'data' => $this->mergeStatusData([
                    'stage' => 'done',
                    'waiting_for_api' => false,
                ]),
            ]);
        } catch (Throwable $exception) {
            Log::error('[ProfitabilityReport] === ДЖОБА УПАЛА С ОШИБКОЙ ===', [
                'cabinet_id' => $this->cabinetId,
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => mb_substr($exception->getTraceAsString(), 0, 2000),
            ]);
            $this->updateStatusFailed($exception->getMessage());
            throw $exception;
        }
    }

    /**
     * Этот метод Laravel вызовет, если Job упадёт (любое необработанное исключение)
     * или воркер прервет его по таймауту.
     */
    public function failed(Throwable $exception): void
    {
        $this->updateStatusFailed($exception->getMessage());
    }

    private function upsertStatusRecord(): int
    {
        $threshold = now()->subMinutes(5);

        JobStatus::where('job_name', self::class)
            ->where('data->cabinet_id', $this->cabinetId)
            ->where('status', 'processing')
            ->where('updated_at', '<', $threshold)
            ->update([
                'status' => 'failed',
                'error' => 'Выполнение отчёта превысило ограничение по времени',
                'updated_at' => now(),
            ]);

        $existing = JobStatus::where('job_name', self::class)
            ->where('data->cabinet_id', $this->cabinetId)
            ->latest()
            ->first();

        if ($existing) {
            $existing->update([
                'data' => $this->initialStatusData(),
                'status' => 'processing',
                'error' => null,
                'updated_at' => now(),
            ]);

            return $existing->id;
        }

        return JobStatus::create([
            'job_name' => self::class,
            'data' => $this->initialStatusData(),
            'status' => 'processing',
            'error' => null,
        ])->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function initialStatusData(): array
    {
        return [
            'cabinet_id' => $this->cabinetId,
            'user_id' => $this->userId,
            'stage' => 'preparing',
            'batch' => 0,
            'rows_loaded' => 0,
            'waiting_for_api' => false,
            'started_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function updateStatusProgress(string $stage, array $meta = []): void
    {
        if ($this->statusRecordId === null) {
            return;
        }

        JobStatus::where('id', $this->statusRecordId)->update([
            'data' => $this->mergeStatusData(array_merge(['stage' => $stage], $meta)),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function mergeStatusData(array $meta): array
    {
        $record = JobStatus::find($this->statusRecordId);
        $data = $record?->data ?? $this->initialStatusData();

        return array_merge($data, $meta);
    }

    private function forgetProfitabilityReportCache(int $cabinetId): void
    {
        $report = Report::where('cabinet_id', $cabinetId)->first();

        if (! $report) {
            return;
        }

        Cache::forget("profitability_report_{$cabinetId}_{$report->updated_at->timestamp}");
    }

    private function failIfAlreadyProcessing(): bool
    {
        $processing = JobStatus::where('job_name', self::class)
            ->where('data->cabinet_id', $this->cabinetId)
            ->where('status', 'processing')
            ->get();

        if ($processing->isEmpty()) {
            return false;
        }

        foreach ($processing as $record) {
            $record->update([
                'status' => 'failed',
                'error' => 'Отчёт уже выполняется, повторный запрос отклонён.',
                'updated_at' => now(),
            ]);
        }

        return true;
    }

    private function updateStatusFailed(string $message): void
    {
        if ($this->statusRecordId === null) {
            return;
        }

        JobStatus::where('id', $this->statusRecordId)->update([
            'status' => 'failed',
            'error' => $message,
            'data' => $this->mergeStatusData([
                'waiting_for_api' => false,
            ]),
            'updated_at' => now(),
        ]);
    }

    private function notifyTokenExpired(ProfitabilityCabinet $cabinet): void
    {
        try {
            $cabinet->loadMissing('user');

            if (! $cabinet->user) {
                return;
            }

            $cabinet->user->notify(new WbCabinetAuthorizationNotification([
                'type' => 'profitability',
                'cabinet' => $cabinet->name,
            ]));
        } catch (Throwable $exception) {
            Log::warning('Не удалось отправить уведомление о просроченном токене WB', [
                'cabinet_id' => $cabinet->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function isAccessTokenExpired(mixed $data): bool
    {
        $haystack = is_string($data)
            ? $data
            : json_encode($data ?? [], JSON_UNESCAPED_UNICODE);

        if (! $haystack) {
            return false;
        }

        return str_contains(strtolower($haystack), 'access token expired');
    }

    private function formatApiError(mixed $data, ?int $code): string
    {
        $message = is_string($data)
            ? $data
            : json_encode($data ?? [], JSON_UNESCAPED_UNICODE);

        $message = $message ?: 'unknown error';

        return 'Ошибка API: ' . $message . ($code ? " (код: {$code})" : '');
    }

    private function safeDivide(float $numerator, float $denominator): float
    {
        return abs($denominator) > 0.0000001 ? $numerator / $denominator : 0.0;
    }

    /**
     * Подбираем наиболее релевантные кабинеты ценообразования: сначала точный матч по api key/имени,
     * затем fallback на все кабинеты пользователя.
     *
     * @param  array<int, PriceCalculationCabinets>  $priceCalcCabinets
     * @return array<int, int>
     */
    private function resolveRelevantPriceCalcCabinetIds(ProfitabilityCabinet $profitabilityCabinet, array $priceCalcCabinets): array
    {
        $priorityIds = [];
        $allIds = [];

        $profitabilityApiKey = trim((string) ($profitabilityCabinet->apikey ?? ''));
        $profitabilityName = mb_strtolower(trim((string) ($profitabilityCabinet->name ?? '')));

        foreach ($priceCalcCabinets as $priceCabinet) {
            $priceCabinetId = (int) ($priceCabinet->id ?? 0);
            if ($priceCabinetId <= 0) {
                continue;
            }

            $allIds[] = $priceCabinetId;

            $priceApiKey = trim((string) ($priceCabinet->apikey ?? ''));
            $priceName = mb_strtolower(trim((string) ($priceCabinet->name ?? '')));

            if ($profitabilityApiKey !== '' && $priceApiKey !== '' && hash_equals($profitabilityApiKey, $priceApiKey)) {
                $priorityIds[] = $priceCabinetId;
                continue;
            }

            if ($profitabilityName !== '' && $priceName !== '' && $profitabilityName === $priceName) {
                $priorityIds[] = $priceCabinetId;
            }
        }

        return array_values(array_unique(array_merge($priorityIds, $allIds)));
    }

    /**
     * Формирует lookup себестоимости из ценообразования.
     * Матчинг по barcode, затем по nm_id.
     *
     * @param  array<int, int>  $cabinetIds
     * @return array<string, array<string, float>>
     */
    private function buildPriceCalculationCostLookup(array $cabinetIds): array
    {
        if (empty($cabinetIds)) {
            return [
                'v3_by_barcode' => [],
                'v3_by_nm_id' => [],
            ];
        }

        $v3CostBucketsByBarcode = [];
        $v3CostBucketsByNmId = [];

        $v3Rows = PriceCalculationV3Data::whereIn('cabinet_id', $cabinetIds)
            ->whereNotNull('cost_price')
            ->get(['nm_id', 'barcode', 'cost_price']);

        foreach ($v3Rows as $row) {
            $cost = (float) ($row->cost_price ?? 0);
            if ($cost <= 0) {
                continue;
            }

            $normalizedBarcode = $this->normalizeBarcodeForLookup($row->barcode);
            if ($normalizedBarcode !== '') {
                $this->addCostToBucket($v3CostBucketsByBarcode, $normalizedBarcode, $cost);
            }

            $nmId = (int) ($row->nm_id ?? 0);
            if ($nmId > 0) {
                $this->addCostToBucket($v3CostBucketsByNmId, (string) $nmId, $cost);
            }
        }

        return [
            'v3_by_barcode' => $this->finalizeCostBuckets($v3CostBucketsByBarcode),
            'v3_by_nm_id' => $this->finalizeCostBuckets($v3CostBucketsByNmId),
        ];
    }

    /**
     * Применяет себестоимость к операциям продаж/возвратов и рассчитывает базовую маржу.
     *
     * @param  array<int, Item>  $itemsData
     * @param  array<string, mixed>  $totals
     * @param  array<string, string>  $operations
     * @param  array<string, array<string, float>>  $costLookup
     */
    private function applyPurchaseCostsAndBaseMargins(array &$itemsData, array &$totals, array $operations, array $costLookup): void
    {
        $totals['purchase_cost'] = 0;

        foreach ($itemsData as $item) {
            if (! in_array($item->supplier_oper_name, [$operations['sales'], $operations['returns']], true)) {
                continue;
            }

            $purchaseCost = $this->resolvePurchaseCost(
                (int) ($item->nm_id ?? 0),
                $item->barcode,
                $costLookup
            );

            $item->purchase_cost = $purchaseCost;

            if ($item->supplier_oper_name === $operations['returns']) {
                $totals['purchase_cost'] -= $purchaseCost;
                continue;
            }

            $totals['purchase_cost'] += $purchaseCost;
            $item->margin = (float) $item->sum_to_transfer - $purchaseCost;
        }
    }

    /**
     * Возвращает себестоимость из V3: сначала barcode, потом nm_id.
     *
     * @param  array<string, array<string, float>>  $costLookup
     */
    private function resolvePurchaseCost(int $nmId, mixed $barcode, array $costLookup): float
    {
        $normalizedBarcode = $this->normalizeBarcodeForLookup($barcode);
        if ($normalizedBarcode !== '') {
            $v3ByBarcode = $costLookup['v3_by_barcode'] ?? [];
            if (isset($v3ByBarcode[$normalizedBarcode])) {
                return (float) $v3ByBarcode[$normalizedBarcode];
            }
        }

        if ($nmId > 0) {
            $key = (string) $nmId;

            $v3ByNmId = $costLookup['v3_by_nm_id'] ?? [];
            if (isset($v3ByNmId[$key])) {
                return (float) $v3ByNmId[$key];
            }
        }

        return 0.0;
    }

    /**
     * @param array<string, array{sum: float, count: int}> $buckets
     */
    private function addCostToBucket(array &$buckets, string $key, float $cost): void
    {
        if (! isset($buckets[$key])) {
            $buckets[$key] = ['sum' => 0.0, 'count' => 0];
        }

        $buckets[$key]['sum'] += $cost;
        $buckets[$key]['count']++;
    }

    /**
     * @param array<string, array{sum: float, count: int}> $buckets
     * @return array<string, float>
     */
    private function finalizeCostBuckets(array $buckets): array
    {
        $result = [];

        foreach ($buckets as $key => $bucket) {
            $count = (int) ($bucket['count'] ?? 0);
            if ($count <= 0) {
                continue;
            }

            $sum = (float) ($bucket['sum'] ?? 0);
            $result[$key] = round($sum / $count, 2);
        }

        return $result;
    }

    private function normalizeBarcodeForLookup(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value) || is_int($value)) {
            return sprintf('%.0f', (float) $value);
        }

        $barcode = trim((string) $value);
        $barcode = str_replace(["\u{00A0}", ' ', "\t", "\r", "\n", "'"], '', $barcode);

        if ($barcode === '') {
            return '';
        }

        if (stripos($barcode, 'e') !== false && is_numeric($barcode)) {
            return sprintf('%.0f', (float) $barcode);
        }

        if (str_ends_with($barcode, '.0') && is_numeric($barcode)) {
            return substr($barcode, 0, -2);
        }

        return $barcode;
    }

    private function rowValue(array $row, string $newKey, string $legacyKey, mixed $default = null): mixed
    {
        if (array_key_exists($newKey, $row) && $row[$newKey] !== null) {
            return $row[$newKey];
        }

        if (array_key_exists($legacyKey, $row) && $row[$legacyKey] !== null) {
            return $row[$legacyKey];
        }

        return $default;
    }
}
