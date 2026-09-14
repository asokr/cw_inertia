<?php

namespace App\Services\Subscriber\Wb;

use App\Enums\WbHistoryLoadStatus;
use App\Jobs\Wb\StockHistory\ProcessWbOrderHistoryBackfillJob;
use App\Jobs\Wb\StockHistory\ProcessWbStockHistoryBackfillJob;
use App\Models\Subscribers\Wb\StockHistory\WbOrderHistoryDay;
use App\Models\Subscribers\Wb\StockHistory\WbOrderHistoryItem;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistoryDay;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistoryItem;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistoryProduct;
use App\Models\Subscribers\Wb\StockHistory\WbStockHistorySetting;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Wb\StockHistory\WbStockHistorySyncService;
use App\Support\Wb\WbBasketHost;
use App\Support\Wb\WbStockHistoryCalendar;
use App\Support\Wb\WbStockHistoryJobStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class WbStockHistoryService
{
    public const PER_PAGE = 100;

    public function __construct(
        private readonly WbStockHistorySyncService $syncService,
    ) {}

    public function settingsFor(int $cabinetId): WbStockHistorySetting
    {
        return WbStockHistorySetting::query()->firstOrCreate(
            ['cabinet_id' => $cabinetId],
            [
                'retention_days' => WbStockHistorySetting::DEFAULT_RETENTION_DAYS,
                'stocks_tracking_enabled' => false,
                'orders_tracking_enabled' => false,
                'stocks_status' => WbHistoryLoadStatus::Idle,
                'orders_status' => WbHistoryLoadStatus::Idle,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function trackingPayload(WbStockHistorySetting $settings): array
    {
        $hasStocks = WbStockHistoryDay::query()->where('cabinet_id', $settings->cabinet_id)->exists();
        $hasOrders = WbOrderHistoryDay::query()->where('cabinet_id', $settings->cabinet_id)->exists();

        return [
            'retention_days' => (int) $settings->retention_days,
            'min_retention_days' => WbStockHistorySetting::MIN_RETENTION_DAYS,
            'max_retention_days' => WbStockHistorySetting::MAX_RETENTION_DAYS,
            'max_load_days' => WbStockHistoryCalendar::maxLoadDays(),
            'earliest_load_date' => WbStockHistoryCalendar::earliestLoadDate(),
            'today' => WbStockHistoryCalendar::todayDate(),
            'yesterday' => WbStockHistoryCalendar::yesterdayDate(),
            'stocks' => [
                'tracking_enabled' => (bool) $settings->stocks_tracking_enabled,
                'status' => $settings->stocks_status?->value ?? WbHistoryLoadStatus::Idle->value,
                'is_loading' => $settings->stocks_status?->isLoading() ?? false,
                'last_error' => $settings->stocks_last_error,
                'has_history' => $hasStocks,
                'available_from' => $this->minDate(WbStockHistoryDay::class, 'stock_date', (int) $settings->cabinet_id),
                'available_to' => $this->maxDate(WbStockHistoryDay::class, 'stock_date', (int) $settings->cabinet_id),
                'job' => WbStockHistoryJobStatus::payload((int) $settings->cabinet_id, 'stocks'),
            ],
            'orders' => [
                'tracking_enabled' => (bool) $settings->orders_tracking_enabled,
                'status' => $settings->orders_status?->value ?? WbHistoryLoadStatus::Idle->value,
                'is_loading' => $settings->orders_status?->isLoading() ?? false,
                'last_error' => $settings->orders_last_error,
                'has_history' => $hasOrders,
                'available_from' => $this->minDate(WbOrderHistoryDay::class, 'order_date', (int) $settings->cabinet_id),
                'available_to' => $this->maxDate(WbOrderHistoryDay::class, 'order_date', (int) $settings->cabinet_id),
                'job' => WbStockHistoryJobStatus::payload((int) $settings->cabinet_id, 'orders'),
            ],
            'is_loading' => ($settings->stocks_status?->isLoading() ?? false)
                || ($settings->orders_status?->isLoading() ?? false),
        ];
    }

    /**
     * @return array{from: string, to: string, dates: list<string>}
     */
    public function resolvePeriod(Request $request): array
    {
        $today = WbStockHistoryCalendar::todayDate();
        $defaultFrom = WbStockHistoryCalendar::today()
            ->subDays(WbStockHistoryCalendar::DEFAULT_PERIOD_DAYS - 1)
            ->toDateString();
        if ($defaultFrom > $today) {
            $defaultFrom = $today;
        }

        $to = $this->normalizeDate((string) $request->input('to', $today), $today);
        if ($to > $today) {
            $to = $today;
        }
        $from = $this->normalizeDate((string) $request->input('from', $defaultFrom), $defaultFrom);
        $earliest = WbStockHistoryCalendar::earliestLoadDate();
        if ($from < $earliest) {
            $from = $earliest;
        }
        if ($from > $to) {
            $from = $to;
        }

        return [
            'from' => $from,
            'to' => $to,
            'dates' => $this->syncService->dateList($from, $to),
        ];
    }

    /**
     * @param  array{from: string, to: string, dates: list<string>}  $period
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>, loaded_dates: list<string>}
     */
    public function listStocks(WbCabinet $cabinet, Request $request, array $period): array
    {
        $cabinetId = (int) $cabinet->id;
        $from = $period['from'];
        $to = $period['to'];
        $dates = $period['dates'];
        $page = max(1, (int) $request->input('page', 1));

        $loadedDates = WbStockHistoryDay::query()
            ->where('cabinet_id', $cabinetId)
            ->whereBetween('stock_date', [$from, $to])
            ->pluck('stock_date')
            ->map(static fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        $pairs = WbStockHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->whereBetween('stock_date', [$from, $to])
            ->select('nm_id', 'chrt_id')
            ->distinct()
            ->get();

        $current = $this->syncService->currentStocksBySize($cabinetId);
        $filters = $this->filters($request);

        $products = WbStockHistoryProduct::query()
            ->where('cabinet_id', $cabinetId)
            ->get()
            ->keyBy(static fn (WbStockHistoryProduct $p): string => $p->nm_id.':'.$p->chrt_id);

        $rows = [];
        foreach ($pairs as $pair) {
            $key = $pair->nm_id.':'.$pair->chrt_id;
            $product = $products->get($key);
            $row = [
                'nm_id' => (int) $pair->nm_id,
                'chrt_id' => (int) $pair->chrt_id,
                'image_url' => WbBasketHost::smallImageUrl((int) $pair->nm_id),
                'subject' => $product?->subject,
                'vendor_code' => $product?->vendor_code,
                'tech_size' => $product?->tech_size,
                'barcode' => $product?->barcode,
                'quantity' => (int) ($current[$key]['quantity'] ?? 0),
                'in_way_to_client' => (int) ($current[$key]['in_way_to_client'] ?? 0),
                'in_way_from_client' => (int) ($current[$key]['in_way_from_client'] ?? 0),
            ];
            if (! $this->matchesFilters($row, $filters)) {
                continue;
            }
            $rows[] = $row;
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['subject'] ?? '', $a['vendor_code'] ?? '', $a['nm_id'], $a['tech_size'] ?? '']
                <=> [$b['subject'] ?? '', $b['vendor_code'] ?? '', $b['nm_id'], $b['tech_size'] ?? ''];
        });

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);
        $slice = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $seriesMap = $this->sizeStockSeries($cabinetId, $slice, $from, $to);

        $items = [];
        foreach ($slice as $row) {
            $mapKey = $row['nm_id'].':'.$row['chrt_id'];
            $byDate = $seriesMap[$mapKey] ?? [];
            $series = [];
            foreach ($dates as $date) {
                $series[] = array_key_exists($date, $byDate) ? (int) $byDate[$date] : null;
            }
            $items[] = array_merge($row, ['series' => $series]);
        }

        return [
            'items' => $items,
            'loaded_dates' => $loadedDates,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => self::PER_PAGE,
                'total' => $total,
            ],
        ];
    }

    /**
     * @param  array{from: string, to: string, dates: list<string>}  $period
     * @return array{items: list<array<string, mixed>>, meta: array<string, mixed>, loaded_dates: list<string>}
     */
    public function listOrders(int $cabinetId, Request $request, array $period): array
    {
        $from = $period['from'];
        $to = $period['to'];
        $dates = $period['dates'];
        $page = max(1, (int) $request->input('page', 1));
        $filters = $this->filters($request);

        $loadedDates = WbOrderHistoryDay::query()
            ->where('cabinet_id', $cabinetId)
            ->whereBetween('order_date', [$from, $to])
            ->pluck('order_date')
            ->map(static fn ($d) => Carbon::parse($d)->toDateString())
            ->all();
        $loadedSet = array_fill_keys($loadedDates, true);

        $query = WbOrderHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->whereBetween('order_date', [$from, $to]);

        $this->applyOrderFilters($query, $filters);

        $pairs = (clone $query)
            ->select('nm_id', 'tech_size', 'barcode', 'vendor_code', 'subject')
            ->distinct()
            ->orderBy('subject')
            ->orderBy('vendor_code')
            ->orderBy('nm_id')
            ->orderBy('tech_size')
            ->orderBy('barcode')
            ->get();

        $total = $pairs->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);
        $slice = $pairs->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();

        $counts = collect();
        if ($slice->isNotEmpty()) {
            $counts = WbOrderHistoryItem::query()
                ->where('cabinet_id', $cabinetId)
                ->whereBetween('order_date', [$from, $to])
                ->where(function ($inner) use ($slice): void {
                    foreach ($slice as $row) {
                        $inner->orWhere(function ($q) use ($row): void {
                            $q->where('nm_id', $row->nm_id)
                                ->where('tech_size', $row->tech_size)
                                ->where('barcode', $row->barcode);
                        });
                    }
                })
                ->get();
        }

        $byKey = [];
        foreach ($counts as $row) {
            $key = $row->nm_id."\n".$row->tech_size."\n".$row->barcode;
            $date = Carbon::parse($row->order_date)->toDateString();
            $byKey[$key][$date] = (int) $row->orders_count;
        }

        $items = [];
        foreach ($slice as $row) {
            $key = $row->nm_id."\n".$row->tech_size."\n".$row->barcode;
            $series = [];
            foreach ($dates as $date) {
                if (! isset($loadedSet[$date])) {
                    $series[] = null;

                    continue;
                }
                $series[] = (int) ($byKey[$key][$date] ?? 0);
            }
            $items[] = [
                'nm_id' => (int) $row->nm_id,
                'tech_size' => (string) $row->tech_size,
                'barcode' => (string) $row->barcode,
                'vendor_code' => $row->vendor_code,
                'subject' => $row->subject,
                'image_url' => WbBasketHost::smallImageUrl((int) $row->nm_id),
                'series' => $series,
            ];
        }

        return [
            'items' => $items,
            'loaded_dates' => $loadedDates,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => self::PER_PAGE,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function queueStockLoad(WbCabinet $cabinet): array
    {
        $settings = $this->settingsFor((int) $cabinet->id);
        if ($settings->stocks_status?->isLoading()) {
            return [
                'success' => false,
                'messages' => ['Загрузка остатков уже идёт.'],
            ];
        }

        $period = $this->defaultLoadPeriod();

        $settings->stocks_status = WbHistoryLoadStatus::Loading;
        $settings->stocks_last_error = null;
        $settings->save();
        WbStockHistoryJobStatus::start((int) $cabinet->id, 'stocks');

        ProcessWbStockHistoryBackfillJob::dispatch(
            (int) $cabinet->id,
            $period['from'],
            $period['to'],
            false,
            true,
        );

        return [
            'success' => true,
            'messages' => ['Собираем историю остатков.'],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function queueStockRefresh(WbCabinet $cabinet, string $from): array
    {
        $settings = $this->settingsFor((int) $cabinet->id);
        if ($settings->stocks_status?->isLoading()) {
            return [
                'success' => false,
                'messages' => ['Загрузка остатков уже идёт.'],
            ];
        }

        $period = $this->assertLoadPeriod($from);
        if ($period['success'] === false) {
            return $period;
        }

        $settings->stocks_status = WbHistoryLoadStatus::Loading;
        $settings->stocks_last_error = null;
        $settings->save();
        WbStockHistoryJobStatus::start((int) $cabinet->id, 'stocks', 'refresh');

        ProcessWbStockHistoryBackfillJob::dispatch(
            (int) $cabinet->id,
            $period['from'],
            $period['to'],
            true,
            false,
        );

        return [
            'success' => true,
            'messages' => ['Обновляем исторические остатки.'],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function startStocksTracking(int $cabinetId): array
    {
        $settings = $this->settingsFor($cabinetId);
        if ($settings->stocks_status?->isLoading()) {
            return [
                'success' => false,
                'messages' => ['Загрузка остатков уже идёт.'],
            ];
        }
        if ($settings->stocks_tracking_enabled) {
            return [
                'success' => true,
                'messages' => ['История уже обновляется автоматически.'],
            ];
        }

        $settings->stocks_tracking_enabled = true;
        $settings->stocks_status = WbHistoryLoadStatus::Active;
        $settings->stocks_last_error = null;
        $settings->save();

        return [
            'success' => true,
            'messages' => ['История будет обновляться автоматически каждый день.'],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function stopStocksTracking(int $cabinetId): array
    {
        $settings = $this->settingsFor($cabinetId);
        $settings->stocks_tracking_enabled = false;
        if (! $settings->stocks_status?->isLoading()) {
            $settings->stocks_status = WbHistoryLoadStatus::Idle;
        }
        $settings->stocks_last_error = null;
        $settings->save();

        return [
            'success' => true,
            'messages' => ['Автообновление остановлено. Уже собранная история остаётся на экране.'],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function startOrdersTracking(int $cabinetId): array
    {
        $settings = $this->settingsFor($cabinetId);
        if ($settings->orders_status?->isLoading()) {
            return [
                'success' => false,
                'messages' => ['Загрузка заказов уже идёт.'],
            ];
        }
        if ($settings->orders_tracking_enabled) {
            return [
                'success' => true,
                'messages' => ['История уже обновляется автоматически.'],
            ];
        }

        $settings->orders_tracking_enabled = true;
        $settings->orders_status = WbHistoryLoadStatus::Active;
        $settings->orders_last_error = null;
        $settings->save();

        return [
            'success' => true,
            'messages' => ['История будет обновляться автоматически каждый день.'],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function stopOrdersTracking(int $cabinetId): array
    {
        $settings = $this->settingsFor($cabinetId);
        $settings->orders_tracking_enabled = false;
        if (! $settings->orders_status?->isLoading()) {
            $settings->orders_status = WbHistoryLoadStatus::Idle;
        }
        $settings->orders_last_error = null;
        $settings->save();

        return [
            'success' => true,
            'messages' => ['Автообновление остановлено. Уже собранная история остаётся на экране.'],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function queueOrderLoad(WbCabinet $cabinet): array
    {
        $settings = $this->settingsFor((int) $cabinet->id);
        if ($settings->orders_status?->isLoading()) {
            return [
                'success' => false,
                'messages' => ['Загрузка заказов уже идёт.'],
            ];
        }

        $period = $this->defaultLoadPeriod();

        $settings->orders_status = WbHistoryLoadStatus::Loading;
        $settings->orders_last_error = null;
        $settings->save();
        WbStockHistoryJobStatus::start((int) $cabinet->id, 'orders');

        ProcessWbOrderHistoryBackfillJob::dispatch((int) $cabinet->id, $period['from'], $period['to']);

        return [
            'success' => true,
            'messages' => ['Собираем историю заказов.'],
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>}
     */
    public function updateRetention(int $cabinetId, int $days): array
    {
        $days = max(
            WbStockHistorySetting::MIN_RETENTION_DAYS,
            min(WbStockHistorySetting::MAX_RETENTION_DAYS, $days),
        );

        $settings = $this->settingsFor($cabinetId);
        $settings->retention_days = $days;
        $settings->save();
        $this->syncService->pruneCabinet($cabinetId, $days);

        return [
            'success' => true,
            'messages' => ['Срок хранения обновлён.'],
        ];
    }

    /**
     * Первая загрузка всегда за два календарных месяца по сегодня.
     *
     * @return array{success: bool, messages: list<string>, from: string, to: string}
     */
    private function defaultLoadPeriod(): array
    {
        return [
            'success' => true,
            'messages' => [],
            'from' => WbStockHistoryCalendar::earliestLoadDate(),
            'to' => WbStockHistoryCalendar::todayDate(),
        ];
    }

    /**
     * Конец загрузки всегда сегодня (московский календарь).
     *
     * @return array{success: bool, messages: list<string>, from?: string, to?: string}
     */
    private function assertLoadPeriod(string $from): array
    {
        $today = WbStockHistoryCalendar::todayDate();
        $earliest = WbStockHistoryCalendar::earliestLoadDate();
        $from = $this->normalizeDate($from, $earliest);
        $to = $today;

        if ($from > $to) {
            return [
                'success' => false,
                'messages' => ['Дата начала не может быть позже сегодняшнего дня.'],
            ];
        }
        // Слишком раннюю дату подрезаем до окна в два месяца — не ругаемся на устаревшее значение с экрана.
        if ($from < $earliest) {
            $from = $earliest;
        }

        return [
            'success' => true,
            'messages' => [],
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * История размера: итог `_total`, иначе сумма старых строк по складам.
     *
     * @param  list<array<string, mixed>>  $slice
     * @return array<string, array<string, int>>
     */
    private function sizeStockSeries(int $cabinetId, array $slice, string $from, string $to): array
    {
        if ($slice === []) {
            return [];
        }

        $items = WbStockHistoryItem::query()
            ->where('cabinet_id', $cabinetId)
            ->whereBetween('stock_date', [$from, $to])
            ->where(function ($inner) use ($slice): void {
                foreach ($slice as $row) {
                    $inner->orWhere(function ($q) use ($row): void {
                        $q->where('nm_id', $row['nm_id'])->where('chrt_id', $row['chrt_id']);
                    });
                }
            })
            ->get();

        $totals = [];
        $others = [];
        foreach ($items as $item) {
            $key = $item->nm_id.':'.$item->chrt_id;
            $date = Carbon::parse($item->stock_date)->toDateString();
            if ((string) $item->warehouse_key === WbStockHistorySyncService::TOTAL_WAREHOUSE_KEY) {
                $totals[$key][$date] = (int) $item->qty;
                continue;
            }
            $others[$key][$date] = ($others[$key][$date] ?? 0) + (int) $item->qty;
        }

        $map = [];
        foreach ($slice as $row) {
            $key = $row['nm_id'].':'.$row['chrt_id'];
            $map[$key] = $totals[$key] ?? ($others[$key] ?? []);
        }

        return $map;
    }

    /**
     * @return array{subject: string, vendor_code: string, nm_id: string, tech_size: string, barcode: string}
     */
    private function filters(Request $request): array
    {
        return [
            'subject' => trim((string) $request->input('subject', '')),
            'vendor_code' => trim((string) $request->input('vendor_code', $request->input('search', ''))),
            'nm_id' => trim((string) $request->input('nm_id', '')),
            'tech_size' => trim((string) $request->input('tech_size', '')),
            'barcode' => trim((string) $request->input('barcode', '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{subject: string, vendor_code: string, nm_id: string, tech_size: string, barcode: string}  $filters
     */
    private function matchesFilters(array $row, array $filters): bool
    {
        if ($filters['subject'] !== '' && ! $this->contains((string) ($row['subject'] ?? ''), $filters['subject'])) {
            return false;
        }
        if ($filters['vendor_code'] !== '') {
            $hay = (string) ($row['vendor_code'] ?? '').' '.(string) ($row['subject'] ?? '').' '.(string) ($row['nm_id'] ?? '');
            if (! $this->contains($hay, $filters['vendor_code'])) {
                return false;
            }
        }
        if ($filters['nm_id'] !== '' && ! str_contains((string) ($row['nm_id'] ?? ''), $filters['nm_id'])) {
            return false;
        }
        if ($filters['tech_size'] !== '' && ! $this->contains((string) ($row['tech_size'] ?? ''), $filters['tech_size'])) {
            return false;
        }
        if ($filters['barcode'] !== '' && ! $this->contains((string) ($row['barcode'] ?? ''), $filters['barcode'])) {
            return false;
        }

        return true;
    }

    /**
     * @param  Builder<WbOrderHistoryItem>  $query
     * @param  array{subject: string, vendor_code: string, nm_id: string, tech_size: string, barcode: string}  $filters
     */
    private function applyOrderFilters($query, array $filters): void
    {
        if ($filters['subject'] !== '') {
            $like = '%'.addcslashes($filters['subject'], '%_\\').'%';
            $query->where('subject', 'like', $like);
        }
        if ($filters['vendor_code'] !== '') {
            $like = '%'.addcslashes($filters['vendor_code'], '%_\\').'%';
            $query->where(function ($inner) use ($like): void {
                $inner->where('vendor_code', 'like', $like)
                    ->orWhere('subject', 'like', $like)
                    ->orWhere('nm_id', 'like', $like)
                    ->orWhere('barcode', 'like', $like);
            });
        }
        if ($filters['nm_id'] !== '') {
            $query->where('nm_id', 'like', '%'.addcslashes($filters['nm_id'], '%_\\').'%');
        }
        if ($filters['tech_size'] !== '') {
            $like = '%'.addcslashes($filters['tech_size'], '%_\\').'%';
            $query->where('tech_size', 'like', $like);
        }
        if ($filters['barcode'] !== '') {
            $like = '%'.addcslashes($filters['barcode'], '%_\\').'%';
            $query->where('barcode', 'like', $like);
        }
    }

    private function contains(string $haystack, string $needle): bool
    {
        return mb_stripos($haystack, $needle) !== false;
    }

    private function normalizeDate(string $value, string $fallback): string
    {
        try {
            return Carbon::parse($value, WbStockHistoryCalendar::TIMEZONE)->toDateString();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * @param  class-string  $model
     */
    private function minDate(string $model, string $column, int $cabinetId): ?string
    {
        $value = $model::query()->where('cabinet_id', $cabinetId)->min($column);

        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    /**
     * @param  class-string  $model
     */
    private function maxDate(string $model, string $column, int $cabinetId): ?string
    {
        $value = $model::query()->where('cabinet_id', $cabinetId)->max($column);

        return $value ? Carbon::parse($value)->toDateString() : null;
    }
}
