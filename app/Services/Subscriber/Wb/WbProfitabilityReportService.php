<?php

namespace App\Services\Subscriber\Wb;

use App\Exports\Wb\ProfitabilityExport;
use App\Http\Traits\WBApiTrait;
use App\Jobs\ExportProfitabilityReportJob;
use App\Jobs\ProcessProfitabilityReport;
use App\Models\JobStatus;
use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;
use App\Support\ProfitabilityJobStatusPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class WbProfitabilityReportService
{
    use WBApiTrait;

    private const ITEM_PAGE_SIZE = 100;

    private const EXPORT_CACHE_TTL_SECONDS = 86400;

    private const EXPORT_STALE_MINUTES = 30;

    /** Макс. строк на лист (Прочее — суммарно по операциям листа). */
    public const MAX_EXPORT_ROWS_PER_SHEET = 50000;

    private const EXPORT_DISK = 'private';

    private const OTHER_OPERATIONS = [
        'Штраф',
        'Платная приемка',
        'Удержание',
        'Коррекция логистики',
    ];

    private const OTHER_SHEET_OPERATIONS = [
        'Штраф',
        'Платная приемка',
        'Удержание',
        'Коррекция логистики',
        'Хранение',
    ];

    private const GROUP_MAP = [
        'sales' => 'Продажа',
        'returns' => 'Возврат',
        'logistics' => 'Логистика',
    ];

    private const EXPORT_STAGE_LABELS = [
        'queued' => 'Встали в очередь…',
        'counting' => 'Считаем строки…',
        'summary' => 'Пишем итоги…',
        'sales' => 'Пишем продажи…',
        'returns' => 'Пишем возвраты…',
        'logistics' => 'Пишем логистику…',
        'other' => 'Пишем прочие операции…',
        'writing' => 'Сохраняем файл…',
        'done' => 'Файл готов',
    ];

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required|exists:wb_profitability_cabinets,id',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'dop_rashod' => 'nullable|numeric|min:0',
            'nalog_percent' => 'nullable|numeric|min:0|max:100',
        ], [
            'cabinet_id.exists' => 'Такого кабинета не существует',
            'required' => 'Не указаны необходимые параметры',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $cabinet = ProfitabilityCabinet::findOrFail($request->cabinet_id);

        if ($this->hasActiveReportJob($cabinet->id)) {
            return response()->json([
                'success' => false,
                'messages' => ['Отчёт уже формируется. Дождитесь завершения — прогресс отображается под формой.'],
            ], 200);
        }

        ProcessProfitabilityReport::dispatch(
            $cabinet->id,
            $request->date_from,
            $request->date_to,
            $request->user()->id,
            (float) $request->input('dop_rashod', 0),
            (float) $request->input('nalog_percent', 0)
        )->onQueue('profitability');

        $this->markReportQueued($cabinet->id, (int) $request->user()->id);

        return response()->json([
            'success' => true,
            'messages' => ['Обновление поставлено в очередь'],
        ], 200);
    }

    /**
     * Лёгкие данные страницы кабинета (без items).
     *
     * @return array{
     *     jobStatus: array<string, mixed>,
     *     report: array<string, mixed>|null,
     *     widget: array<string, mixed>|null,
     *     groupMeta: array{sales: int, returns: int, logistics: int, other: int}
     * }
     */
    public function getCabinetPageData(int $cabinetId, int $userId): array
    {
        $cabinet = DB::table('wb_profitability_cabinets')
            ->select(['id', 'user_id'])
            ->where('id', $cabinetId)
            ->first();

        if (! $cabinet || (int) $cabinet->user_id !== $userId) {
            abort(403);
        }

        $jobStatus = $this->loadJobStatus($cabinetId);

        $reportRow = DB::table('wb_profitability_reports')
            ->where('cabinet_id', $cabinetId)
            ->orderByDesc('updated_at')
            ->first();

        if (! $reportRow) {
            return [
                'jobStatus' => $jobStatus,
                'report' => null,
                'widget' => null,
                'groupMeta' => ['sales' => 0, 'returns' => 0, 'logistics' => 0, 'other' => 0],
            ];
        }

        $report = $this->reportRowToArray($reportRow);
        $reportId = (int) $reportRow->id;
        $groupMeta = $this->loadGroupMeta($reportId);
        $widget = $this->buildWidget($report, $reportId);

        return [
            'jobStatus' => $jobStatus,
            'report' => $report,
            'widget' => $widget,
            'groupMeta' => $groupMeta,
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int, has_more: bool}}
     */
    public function getItemsPage(int $cabinetId, int $userId, Request $request): array
    {
        $cabinet = DB::table('wb_profitability_cabinets')
            ->select(['id', 'user_id'])
            ->where('id', $cabinetId)
            ->first();

        if (! $cabinet || (int) $cabinet->user_id !== $userId) {
            abort(403);
        }

        $validated = Validator::make($request->all(), [
            'group' => ['required', Rule::in(['sales', 'returns', 'logistics', 'other'])],
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:200',
            'search' => 'nullable|string|max:200',
        ])->validate();

        $group = $validated['group'];
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? self::ITEM_PAGE_SIZE);
        $search = trim((string) ($validated['search'] ?? ''));

        $reportId = DB::table('wb_profitability_reports')
            ->where('cabinet_id', $cabinetId)
            ->orderByDesc('updated_at')
            ->value('id');

        if (! $reportId) {
            return [
                'data' => [],
                'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => 0, 'has_more' => false],
            ];
        }

        if ($group === 'other') {
            return $this->paginateOtherItems((int) $reportId, $page, $perPage, $search);
        }

        $operName = self::GROUP_MAP[$group];
        $query = DB::table('wb_profitability_items')
            ->where('report_id', $reportId)
            ->where('supplier_oper_name', $operName);

        $this->applyItemsSearch($query, $search);

        $total = (int) (clone $query)->count();
        $rows = $query
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get($this->itemSelectColumns());

        return [
            'data' => $rows->map(fn ($row) => $this->mapItemRow($row))->all(),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($page * $perPage) < $total,
            ],
        ];
    }

    /**
     * @return array{success: bool, status: string, message: string, ready: bool, stage?: string|null, stage_label?: string|null, truncated?: bool}
     */
    public function startExport(int $cabinetId, int $userId): array
    {
        $cabinet = DB::table('wb_profitability_cabinets')
            ->select(['id', 'user_id', 'name'])
            ->where('id', $cabinetId)
            ->first();

        if (! $cabinet || (int) $cabinet->user_id !== $userId) {
            abort(403);
        }

        $reportRow = DB::table('wb_profitability_reports')
            ->where('cabinet_id', $cabinetId)
            ->orderByDesc('updated_at')
            ->first();

        if (! $reportRow) {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => 'Отчёт ещё не сформирован',
                'ready' => false,
            ];
        }

        $reportId = (int) $reportRow->id;
        $reportUpdatedAt = $this->normalizeReportUpdatedAt($reportRow->updated_at ?? null);
        $current = $this->getExportState($cabinetId);

        if (
            ($current['status'] ?? null) === 'processing'
            && $this->isExportStateFresh($current)
        ) {
            return [
                'success' => true,
                'status' => 'processing',
                'message' => (string) ($current['stage_label'] ?? 'Файл уже формируется…'),
                'ready' => false,
                'stage' => $current['stage'] ?? 'queued',
                'stage_label' => $current['stage_label'] ?? self::EXPORT_STAGE_LABELS['queued'],
                'truncated' => (bool) ($current['truncated'] ?? false),
                'updated_at' => $current['updated_at'] ?? null,
            ];
        }

        // Готовый файл для того же отчёта (id + updated_at) — отдаём сразу.
        // report_id один на кабинет (updateOrCreate), поэтому без fingerprint
        // после пересчёта отдавался бы устаревший xlsx.
        if (
            ($current['status'] ?? null) === 'done'
            && (int) ($current['report_id'] ?? 0) === $reportId
            && $this->exportMatchesReport($current, $reportUpdatedAt)
            && ! empty($current['path'])
            && Storage::disk(self::EXPORT_DISK)->exists($current['path'])
        ) {
            return [
                'success' => true,
                'status' => 'done',
                'message' => 'Файл готов',
                'ready' => true,
                'stage' => 'done',
                'stage_label' => self::EXPORT_STAGE_LABELS['done'],
                'truncated' => (bool) ($current['truncated'] ?? false),
                'truncated_note' => $current['truncated_note'] ?? null,
            ];
        }

        $filename = sprintf(
            'Расчёт_рентабельности_%s_за_%s_по_%s.xlsx',
            $this->safeFilenamePart((string) ($cabinet->name ?? $cabinetId)),
            $reportRow->date_from ?? 'from',
            $reportRow->date_to ?? 'to',
        );

        $this->putExportState($cabinetId, [
            'status' => 'processing',
            'stage' => 'queued',
            'stage_label' => self::EXPORT_STAGE_LABELS['queued'],
            'path' => null,
            'filename' => $filename,
            'error' => null,
            'report_id' => $reportId,
            'report_updated_at' => $reportUpdatedAt,
            'truncated' => false,
            'truncated_note' => null,
            'updated_at' => now()->toIso8601String(),
        ]);

        ExportProfitabilityReportJob::dispatch($cabinetId, $userId, $reportId);

        return [
            'success' => true,
            'status' => 'processing',
            'message' => self::EXPORT_STAGE_LABELS['queued'],
            'ready' => false,
            'stage' => 'queued',
            'stage_label' => self::EXPORT_STAGE_LABELS['queued'],
            'truncated' => false,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function exportStatus(int $cabinetId, int $userId): array
    {
        $cabinet = DB::table('wb_profitability_cabinets')
            ->select(['id', 'user_id'])
            ->where('id', $cabinetId)
            ->first();

        if (! $cabinet || (int) $cabinet->user_id !== $userId) {
            abort(403);
        }

        $state = $this->getExportState($cabinetId);

        if ($state === []) {
            return [
                'status' => 'idle',
                'ready' => false,
                'message' => null,
                'error' => null,
                'stage' => null,
                'stage_label' => null,
                'truncated' => false,
                'updated_at' => null,
            ];
        }

        $status = (string) ($state['status'] ?? 'idle');
        $reportId = (int) ($state['report_id'] ?? 0);

        // Job не стартовал / завис / упал до failed() — не оставляем UI в вечном processing
        if ($status === 'processing' && ! $this->isExportStateFresh($state)) {
            $this->markExportFailed(
                $cabinetId,
                $reportId,
                'Не удалось выгрузить отчёт. Попробуйте чуть позже.'
            );
            $state = $this->getExportState($cabinetId);
            $status = (string) ($state['status'] ?? 'failed');
        }

        $ready = $status === 'done'
            && ! empty($state['path'])
            && Storage::disk(self::EXPORT_DISK)->exists($state['path']);

        // done без файла — считаем ошибкой, иначе фронт крутит poll вечно
        if ($status === 'done' && ! $ready) {
            $this->markExportFailed(
                $cabinetId,
                $reportId,
                'Не удалось выгрузить отчёт. Попробуйте чуть позже.'
            );
            $state = $this->getExportState($cabinetId);
            $status = 'failed';
        }

        $friendlyError = 'Не удалось выгрузить отчёт. Попробуйте чуть позже.';
        $stageLabel = $state['stage_label']
            ?? self::EXPORT_STAGE_LABELS[(string) ($state['stage'] ?? '')]
            ?? null;

        return [
            'status' => $status,
            'ready' => $ready,
            'message' => match ($status) {
                'processing' => $stageLabel ?: 'Готовим файл…',
                'done' => 'Файл готов',
                'failed' => $friendlyError,
                default => null,
            },
            'error' => $status === 'failed'
                ? ($state['error'] ?: $friendlyError)
                : null,
            'stage' => $state['stage'] ?? null,
            'stage_label' => $stageLabel,
            'truncated' => (bool) ($state['truncated'] ?? false),
            'truncated_note' => $state['truncated_note'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
        ];
    }

    /**
     * @return array{path: string, filename: string, disk: string}|null
     */
    public function resolveExportDownload(int $cabinetId, int $userId): ?array
    {
        $cabinet = DB::table('wb_profitability_cabinets')
            ->select(['id', 'user_id'])
            ->where('id', $cabinetId)
            ->first();

        if (! $cabinet || (int) $cabinet->user_id !== $userId) {
            abort(403);
        }

        $state = $this->getExportState($cabinetId);

        if (($state['status'] ?? null) !== 'done' || empty($state['path'])) {
            return null;
        }

        if (! Storage::disk(self::EXPORT_DISK)->exists($state['path'])) {
            return null;
        }

        // Не отдаём файл, если отчёт пересчитан после генерации xlsx
        $reportUpdatedAt = DB::table('wb_profitability_reports')
            ->where('cabinet_id', $cabinetId)
            ->when(
                ! empty($state['report_id']),
                fn ($q) => $q->where('id', (int) $state['report_id'])
            )
            ->orderByDesc('updated_at')
            ->value('updated_at');

        if (! $this->exportMatchesReport($state, $this->normalizeReportUpdatedAt($reportUpdatedAt))) {
            return null;
        }

        return [
            'path' => $state['path'],
            'filename' => (string) ($state['filename'] ?? 'profitability.xlsx'),
            'disk' => self::EXPORT_DISK,
        ];
    }

    public function runExportJob(int $cabinetId, int $userId, int $reportId): void
    {
        $cabinet = DB::table('wb_profitability_cabinets')
            ->select(['id', 'user_id', 'name'])
            ->where('id', $cabinetId)
            ->first();

        if (! $cabinet || (int) $cabinet->user_id !== $userId) {
            throw new \RuntimeException('Кабинет не найден');
        }

        $reportRow = DB::table('wb_profitability_reports')
            ->where('id', $reportId)
            ->where('cabinet_id', $cabinetId)
            ->first();

        if (! $reportRow) {
            throw new \RuntimeException('Отчёт не найден');
        }

        $this->touchExportStage($cabinetId, $reportId, 'counting');

        $report = $this->reportRowToArray($reportRow);
        $limitsMeta = $this->resolveExportSheetLimits($reportId);
        $sheetLimits = $limitsMeta['limits'];
        $truncated = $limitsMeta['truncated'];
        $truncatedNote = $truncated
            ? 'В файле не более '.number_format(self::MAX_EXPORT_ROWS_PER_SHEET, 0, ',', ' ')
                .' строк на раздел. Полных данных больше — выберите более короткий период или смотрите таблицы в кабинете.'
            : null;

        $relativePath = sprintf(
            'wb/profitability/%d/%d/%d.xlsx',
            $userId,
            $cabinetId,
            $reportId
        );

        Storage::disk(self::EXPORT_DISK)->makeDirectory(dirname($relativePath));

        $sheetStageMap = [
            'Итоги' => 'summary',
            'Продажи' => 'sales',
            'Возвраты' => 'returns',
            'Логистика' => 'logistics',
            'Прочее' => 'other',
        ];

        Excel::store(
            new ProfitabilityExport(
                $report,
                $reportId,
                $sheetLimits,
                $truncatedNote,
                function (string $sheetTitle) use ($cabinetId, $reportId, $sheetStageMap) {
                    $stage = $sheetStageMap[$sheetTitle] ?? 'writing';
                    $this->touchExportStage($cabinetId, $reportId, $stage);
                },
            ),
            $relativePath,
            self::EXPORT_DISK
        );

        $filename = sprintf(
            'Расчёт_рентабельности_%s_за_%s_по_%s.xlsx',
            $this->safeFilenamePart((string) ($cabinet->name ?? $cabinetId)),
            $report['date_from'] ?? 'from',
            $report['date_to'] ?? 'to',
        );

        // Перечитываем updated_at после возможного concurrent-update отчёта
        $freshReport = DB::table('wb_profitability_reports')
            ->where('id', $reportId)
            ->where('cabinet_id', $cabinetId)
            ->value('updated_at');

        $current = $this->getExportState($cabinetId);
        $this->putExportState($cabinetId, array_merge($current, [
            'status' => 'done',
            'stage' => 'done',
            'stage_label' => self::EXPORT_STAGE_LABELS['done'],
            'path' => $relativePath,
            'filename' => $filename,
            'error' => null,
            'report_id' => $reportId,
            'report_updated_at' => $this->normalizeReportUpdatedAt($freshReport ?? $reportRow->updated_at ?? null),
            'truncated' => $truncated,
            'truncated_note' => $truncatedNote,
            'updated_at' => now()->toIso8601String(),
        ]));
    }

    public function markExportFailed(int $cabinetId, int $reportId, string $message): void
    {
        $current = $this->getExportState($cabinetId);

        $this->putExportState($cabinetId, [
            'status' => 'failed',
            'stage' => $current['stage'] ?? null,
            'stage_label' => $current['stage_label'] ?? null,
            'path' => $current['path'] ?? null,
            'filename' => $current['filename'] ?? null,
            'error' => mb_substr($message, 0, 500),
            'report_id' => $reportId,
            'report_updated_at' => $current['report_updated_at'] ?? null,
            'truncated' => (bool) ($current['truncated'] ?? false),
            'truncated_note' => $current['truncated_note'] ?? null,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Сбрасывает состояние экспорта и удаляет готовый файл (если есть).
     * Вызывать после пересчёта отчёта, чтобы не отдавать устаревший xlsx.
     */
    public function invalidateExportCache(int $cabinetId): void
    {
        $state = $this->getExportState($cabinetId);

        if (! empty($state['path'])) {
            try {
                Storage::disk(self::EXPORT_DISK)->delete($state['path']);
            } catch (\Throwable) {
                // best-effort
            }
        }

        Cache::forget(self::exportCacheKey($cabinetId));
    }

    public static function exportCacheKey(int $cabinetId): string
    {
        return 'profitability_export_'.$cabinetId;
    }

    /**
     * @return array{limits: array{sales: int|null, returns: int|null, logistics: int|null, other: int|null}, truncated: bool}
     */
    private function resolveExportSheetLimits(int $reportId): array
    {
        $max = self::MAX_EXPORT_ROWS_PER_SHEET;

        $sales = (int) DB::table('wb_profitability_items')
            ->where('report_id', $reportId)
            ->where('supplier_oper_name', 'Продажа')
            ->count();
        $returns = (int) DB::table('wb_profitability_items')
            ->where('report_id', $reportId)
            ->where('supplier_oper_name', 'Возврат')
            ->count();
        $logistics = (int) DB::table('wb_profitability_items')
            ->where('report_id', $reportId)
            ->where('supplier_oper_name', 'Логистика')
            ->count();
        $other = (int) DB::table('wb_profitability_items')
            ->where('report_id', $reportId)
            ->whereIn('supplier_oper_name', self::OTHER_SHEET_OPERATIONS)
            ->count();

        $truncated = $sales > $max || $returns > $max || $logistics > $max || $other > $max;

        return [
            'limits' => [
                'sales' => $sales > $max ? $max : null,
                'returns' => $returns > $max ? $max : null,
                'logistics' => $logistics > $max ? $max : null,
                'other' => $other > $max ? $max : null,
            ],
            'truncated' => $truncated,
        ];
    }

    private function touchExportStage(int $cabinetId, int $reportId, string $stage): void
    {
        $current = $this->getExportState($cabinetId);
        $label = self::EXPORT_STAGE_LABELS[$stage] ?? 'Готовим файл…';

        $this->putExportState($cabinetId, array_merge($current, [
            'status' => 'processing',
            'stage' => $stage,
            'stage_label' => $label,
            'report_id' => $reportId,
            'error' => null,
            'updated_at' => now()->toIso8601String(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function getExportState(int $cabinetId): array
    {
        $state = Cache::get(self::exportCacheKey($cabinetId));

        return is_array($state) ? $state : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function putExportState(int $cabinetId, array $state): void
    {
        Cache::put(self::exportCacheKey($cabinetId), $state, self::EXPORT_CACHE_TTL_SECONDS);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function isExportStateFresh(array $state): bool
    {
        $updatedAt = $state['updated_at'] ?? null;
        if (! is_string($updatedAt) || $updatedAt === '') {
            return false;
        }

        try {
            return \Carbon\Carbon::parse($updatedAt)->greaterThan(now()->subMinutes(self::EXPORT_STALE_MINUTES));
        } catch (\Throwable) {
            return false;
        }
    }

    private function safeFilenamePart(string $value): string
    {
        $clean = preg_replace('/[^\pL\pN\-_ ]+/u', '', $value) ?: 'cabinet';
        $clean = trim(preg_replace('/\s+/', '_', $clean) ?? 'cabinet');

        return mb_substr($clean !== '' ? $clean : 'cabinet', 0, 80);
    }

    /**
     * @param  array<string, mixed>  $exportState
     */
    private function exportMatchesReport(array $exportState, ?string $reportUpdatedAt): bool
    {
        if ($reportUpdatedAt === null || $reportUpdatedAt === '') {
            return false;
        }

        $cached = $this->normalizeReportUpdatedAt($exportState['report_updated_at'] ?? null);

        return $cached !== null && $cached === $reportUpdatedAt;
    }

    private function normalizeReportUpdatedAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{sales: int, returns: int, logistics: int, other: int}
     */
    private function loadGroupMeta(int $reportId): array
    {
        $rows = DB::table('wb_profitability_items')
            ->select('supplier_oper_name', DB::raw('COUNT(*) as cnt'))
            ->where('report_id', $reportId)
            ->groupBy('supplier_oper_name')
            ->pluck('cnt', 'supplier_oper_name');

        $other = 0;
        foreach (self::OTHER_OPERATIONS as $name) {
            $other += (int) ($rows[$name] ?? 0);
        }
        // Хранение показываем одной строкой — считаем как 1, если есть записи
        if ((int) ($rows['Хранение'] ?? 0) > 0) {
            $other += 1;
        }

        return [
            'sales' => (int) ($rows['Продажа'] ?? 0),
            'returns' => (int) ($rows['Возврат'] ?? 0),
            'logistics' => (int) ($rows['Логистика'] ?? 0),
            'other' => $other,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function buildWidget(array $report, int $reportId): array
    {
        $widget = Arr::only($report, [
            'date_from',
            'date_to',
            'sales_quantity',
            'sales_amount',
            'returns_quantity',
            'returns_amount',
            'percent_buy',
            'penalties',
            'logistics',
            'purchase_cost',
            'margin',
            'deduction',
            'storage_fee',
            'acceptance',
            'cashback',
            'dop_rashod',
            'nalog',
            'nalog_percent',
            'correction_sales',
            'total_profitability',
            'itog',
        ]);

        $select = [
            'nm_id',
            DB::raw('MAX(sa_name) as sa_name'),
            DB::raw('SUM(margin) as total_margin'),
            DB::raw('SUM(sum_to_transfer) as total_transfer'),
            DB::raw("SUM(CASE WHEN supplier_oper_name = 'Продажа' THEN quantity ELSE 0 END) as sales_qty"),
            DB::raw("SUM(CASE WHEN supplier_oper_name = 'Продажа' THEN profitability_percent * quantity ELSE 0 END) as sales_profit_sum"),
        ];

        $base = fn () => DB::table('wb_profitability_items')
            ->select($select)
            ->where('report_id', $reportId)
            ->whereNotNull('nm_id')
            ->where('nm_id', '!=', 0)
            ->groupBy('nm_id');

        $topProfitable = $this->mapProductAggregateRows(
            $base()->orderByDesc(DB::raw('SUM(margin)'))->limit(5)->get()
        );
        $topLowMargin = $this->mapProductAggregateRows(
            $base()->orderBy(DB::raw('SUM(margin)'))->limit(5)->get()
        );

        $this->loadProductImages($topProfitable);
        $this->loadProductImages($topLowMargin);

        $widget['top_profitable_products'] = $topProfitable;
        $widget['top_low_margin_products'] = $topLowMargin;

        return $widget;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function mapProductAggregateRows($rows): array
    {
        return $rows->map(function ($row) {
            $totalMargin = round((float) $row->total_margin, 2);
            $totalTransfer = round((float) $row->total_transfer, 2);
            $salesQty = (int) $row->sales_qty;

            $weighted = null;
            if ($salesQty > 0) {
                $weighted = ((float) $row->sales_profit_sum) / $salesQty;
            }

            if (abs($totalTransfer) > 0.00001) {
                $weighted = ($totalMargin / $totalTransfer) * 100;
            }

            return [
                'nm_id' => $row->nm_id,
                'sa_name' => $row->sa_name,
                'profitability_percent' => $weighted !== null ? round($weighted, 2) : null,
                'total_margin' => $totalMargin,
                'total_transfer' => $totalTransfer,
            ];
        })->values()->all();
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int, has_more: bool}}
     */
    private function paginateOtherItems(int $reportId, int $page, int $perPage, string $search): array
    {
        // Хранение — одна агрегированная строка (как на старом UI)
        $storageSum = (float) DB::table('wb_profitability_items')
            ->where('report_id', $reportId)
            ->where('supplier_oper_name', 'Хранение')
            ->sum('sum_to_transfer');

        $storageRow = abs($storageSum) > 0.00001
            ? [[
                'nm_id' => null,
                'sa_name' => null,
                'size' => null,
                'barcode' => null,
                'warehouse' => null,
                'reasoning' => null,
                'quantity' => 0,
                'sum_to_transfer' => round($storageSum, 2),
                'purchase_cost' => 0.0,
                'logistics' => 0.0,
                'cost_adjustments' => 0.0,
                'dop_rashod' => 0.0,
                'cashback' => 0.0,
                'nalog' => 0.0,
                'margin' => 0.0,
                'profitability_percent' => 0.0,
                'type' => 'Хранение',
            ]]
            : [];

        if ($search !== '' && $storageRow !== []) {
            $q = mb_strtolower($search);
            if (! str_contains(mb_strtolower('Хранение'), $q)) {
                $storageRow = [];
            }
        }

        $query = DB::table('wb_profitability_items')
            ->where('report_id', $reportId)
            ->whereIn('supplier_oper_name', self::OTHER_OPERATIONS);

        $this->applyItemsSearch($query, $search);

        $opsTotal = (int) (clone $query)->count();
        $storageCount = count($storageRow);
        $total = $opsTotal + $storageCount;

        // Пагинация: сначала операции, в конце storage-строка
        $offset = ($page - 1) * $perPage;
        $data = [];

        if ($offset < $opsTotal) {
            $rows = $query
                ->orderBy('id')
                ->offset($offset)
                ->limit($perPage)
                ->get($this->itemSelectColumns());

            $typeMap = [
                'Штраф' => 'Штрафы',
                'Платная приемка' => 'Платная приемка',
                'Удержание' => 'Удержание',
                'Коррекция логистики' => 'Коррекция логистики',
            ];

            foreach ($rows as $row) {
                $item = $this->mapItemRow($row);
                $item['type'] = $typeMap[(string) ($row->supplier_oper_name ?? '')] ?? (string) ($row->supplier_oper_name ?? '');
                $data[] = $item;
            }
        }

        $filled = count($data);
        $need = $perPage - $filled;
        if ($need > 0 && $storageCount > 0) {
            // storage попадает только на «хвост» после всех ops
            $storageGlobalIndex = $opsTotal; // 0-based position of storage row
            if ($offset + $filled <= $storageGlobalIndex && $offset + $perPage > $storageGlobalIndex) {
                $data = array_merge($data, $storageRow);
            }
        }

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => ($page * $perPage) < $total,
            ],
        ];
    }

    private function applyItemsSearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';
        $query->where(function ($q) use ($like, $search) {
            $q->where('sa_name', 'like', $like)
                ->orWhere('barcode', 'like', $like)
                ->orWhere('warehouse', 'like', $like)
                ->orWhere('reasoning', 'like', $like);

            if (is_numeric($search)) {
                $q->orWhere('nm_id', (int) $search);
            } else {
                $q->orWhere('nm_id', 'like', $like);
            }
        });
    }

    /**
     * @return list<string>
     */
    private function itemSelectColumns(): array
    {
        return [
            'id',
            'nm_id',
            'sa_name',
            'size',
            'barcode',
            'warehouse',
            'reasoning',
            'quantity',
            'sum_to_transfer',
            'purchase_cost',
            'logistics',
            'cost_adjustments',
            'dop_rashod',
            'cashback',
            'nalog',
            'margin',
            'profitability_percent',
            'supplier_oper_name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapItemRow(object $row): array
    {
        return [
            'nm_id' => $row->nm_id,
            'sa_name' => $row->sa_name,
            'size' => $row->size,
            'barcode' => $row->barcode,
            'warehouse' => $row->warehouse,
            'reasoning' => $row->reasoning,
            'quantity' => (int) ($row->quantity ?? 0),
            'sum_to_transfer' => $this->finiteFloat($row->sum_to_transfer ?? 0),
            'purchase_cost' => $this->finiteFloat($row->purchase_cost ?? 0),
            'logistics' => $this->finiteFloat($row->logistics ?? 0),
            'cost_adjustments' => $this->finiteFloat($row->cost_adjustments ?? 0),
            'dop_rashod' => $this->finiteFloat($row->dop_rashod ?? 0),
            'cashback' => $this->finiteFloat($row->cashback ?? 0),
            'nalog' => $this->finiteFloat($row->nalog ?? 0),
            'margin' => $this->finiteFloat($row->margin ?? 0),
            'profitability_percent' => $this->finiteFloat($row->profitability_percent ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportRowToArray(object $row): array
    {
        return [
            'id' => $row->id ?? null,
            'cabinet_id' => $row->cabinet_id ?? null,
            'date_from' => $row->date_from ?? null,
            'date_to' => $row->date_to ?? null,
            'sales_quantity' => (int) ($row->sales_quantity ?? 0),
            'sales_amount' => $this->finiteFloat($row->sales_amount ?? 0),
            'returns_quantity' => (int) ($row->returns_quantity ?? 0),
            'returns_amount' => $this->finiteFloat($row->returns_amount ?? 0),
            'percent_buy' => $this->finiteFloat($row->percent_buy ?? 0),
            'penalties' => $this->finiteFloat($row->penalties ?? 0),
            'logistics' => $this->finiteFloat($row->logistics ?? 0),
            'purchase_cost' => $this->finiteFloat($row->purchase_cost ?? 0),
            'margin' => $this->finiteFloat($row->margin ?? 0),
            'deduction' => $this->finiteFloat($row->deduction ?? 0),
            'storage_fee' => $this->finiteFloat($row->storage_fee ?? 0),
            'acceptance' => $this->finiteFloat($row->acceptance ?? 0),
            'cashback' => $this->finiteFloat($row->cashback ?? 0),
            'dop_rashod' => $this->finiteFloat($row->dop_rashod ?? 0),
            'nalog' => $this->finiteFloat($row->nalog ?? 0),
            'nalog_percent' => $this->finiteFloat($row->nalog_percent ?? 0),
            'correction_sales' => $this->finiteFloat($row->correction_sales ?? 0),
            'total_profitability' => $this->finiteFloat($row->total_profitability ?? 0),
            'itog' => $this->finiteFloat($row->itog ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJobStatus(int $cabinetId): array
    {
        $lastRecord = JobStatus::where('job_name', ProcessProfitabilityReport::class)
            ->where('data->cabinet_id', $cabinetId)
            ->latest()
            ->first();

        if (! $lastRecord) {
            return [
                'status' => 'done',
                'error' => null,
                'stage' => null,
                'batch' => null,
                'rows_loaded' => null,
                'waiting_for_api' => false,
                'started_at' => null,
                'progress_percent' => null,
                'status_label' => null,
                'status_detail' => null,
            ];
        }

        if (ProfitabilityJobStatusPresenter::isBenignDuplicateFailure($lastRecord)) {
            ProfitabilityJobStatusPresenter::clearBenignDuplicateFailure($lastRecord);
            $lastRecord->refresh();
        }

        return ProfitabilityJobStatusPresenter::fromRecord($lastRecord);
    }

    private function hasActiveReportJob(int $cabinetId): bool
    {
        return JobStatus::where('job_name', ProcessProfitabilityReport::class)
            ->where('data->cabinet_id', $cabinetId)
            ->where('status', 'processing')
            ->exists();
    }

    private function markReportQueued(int $cabinetId, int $userId): void
    {
        $existing = JobStatus::where('job_name', ProcessProfitabilityReport::class)
            ->where('data->cabinet_id', $cabinetId)
            ->latest()
            ->first();

        $payload = [
            'data' => ProfitabilityJobStatusPresenter::initialQueuedData($cabinetId, $userId),
            'status' => 'processing',
            'error' => null,
            'updated_at' => now(),
        ];

        if ($existing) {
            $existing->update($payload);

            return;
        }

        JobStatus::create(array_merge($payload, [
            'job_name' => ProcessProfitabilityReport::class,
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function loadProductImages(array &$products): void
    {
        foreach ($products as &$product) {
            if (empty($product['nm_id'])) {
                $product['image'] = null;
                continue;
            }

            $cacheKey = 'product_image_'.$product['nm_id'];
            try {
                $product['image'] = Cache::remember($cacheKey, 86400 * 7, function () use ($product) {
                    try {
                        $images = $this->getProductImages(1, $product['nm_id']);

                        return $images[0]['imageS'] ?? null;
                    } catch (\Throwable) {
                        return null;
                    }
                });
            } catch (\Throwable) {
                $product['image'] = null;
            }
        }
        unset($product);
    }

    private function finiteFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $float = (float) $value;

        return is_finite($float) ? $float : 0.0;
    }
}
