<?php

namespace App\Support\Wb;

use Illuminate\Support\Facades\Cache;

/**
 * Статус фоновой загрузки истории остатков/заказов WB для панели прогресса.
 */
class WbStockHistoryJobStatus
{
    public const STAGE_QUEUED = 'queued';

    public const STAGE_PRODUCTS = 'products';

    public const STAGE_HISTORY = 'history';

    public const STAGE_WAREHOUSES = 'warehouses';

    public const STAGE_SAVING = 'saving';

    public const STAGE_DONE = 'done';

    /**
     * @return array<string, mixed>
     */
    public static function payload(int $cabinetId, string $tab): array
    {
        $data = Cache::get(self::key($cabinetId, $tab));
        if (! is_array($data)) {
            return [
                'status' => 'done',
                'stage' => null,
                'status_label' => null,
                'started_at' => null,
                'error' => null,
                'progress_percent' => null,
            ];
        }

        return [
            'status' => (string) ($data['status'] ?? 'done'),
            'stage' => $data['stage'] ?? null,
            'status_label' => $data['status_label'] ?? null,
            'started_at' => $data['started_at'] ?? null,
            'error' => $data['error'] ?? null,
            'progress_percent' => isset($data['progress_percent']) ? (int) $data['progress_percent'] : null,
            'action' => $data['action'] ?? null,
        ];
    }

    public static function start(int $cabinetId, string $tab, string $action = 'load'): void
    {
        self::write($cabinetId, $tab, [
            'status' => 'processing',
            'stage' => self::STAGE_QUEUED,
            'status_label' => 'Скоро начнём',
            'started_at' => now()->toIso8601String(),
            'error' => null,
            'progress_percent' => 8,
            'action' => $action,
        ]);
    }

    public static function stage(int $cabinetId, string $tab, string $stage, string $label, int $percent): void
    {
        $current = Cache::get(self::key($cabinetId, $tab), []);
        $startedAt = is_array($current) ? ($current['started_at'] ?? now()->toIso8601String()) : now()->toIso8601String();
        self::write($cabinetId, $tab, [
            'status' => 'processing',
            'stage' => $stage,
            'status_label' => $label,
            'started_at' => $startedAt,
            'error' => null,
            'progress_percent' => $percent,
            'action' => is_array($current) ? ($current['action'] ?? null) : null,
        ]);
    }

    public static function done(int $cabinetId, string $tab, string $label = 'Готово'): void
    {
        $current = Cache::get(self::key($cabinetId, $tab), []);
        $startedAt = is_array($current) ? ($current['started_at'] ?? now()->toIso8601String()) : now()->toIso8601String();
        self::write($cabinetId, $tab, [
            'status' => 'done',
            'stage' => self::STAGE_DONE,
            'status_label' => $label,
            'started_at' => $startedAt,
            'error' => null,
            'progress_percent' => 100,
            'action' => is_array($current) ? ($current['action'] ?? null) : null,
        ]);
    }

    public static function failed(int $cabinetId, string $tab, string $error): void
    {
        $current = Cache::get(self::key($cabinetId, $tab), []);
        $startedAt = is_array($current) ? ($current['started_at'] ?? now()->toIso8601String()) : now()->toIso8601String();
        $stage = is_array($current) ? ($current['stage'] ?? self::STAGE_QUEUED) : self::STAGE_QUEUED;
        self::write($cabinetId, $tab, [
            'status' => 'failed',
            'stage' => $stage,
            'status_label' => $error,
            'started_at' => $startedAt,
            'error' => $error,
            'progress_percent' => 100,
            'action' => is_array($current) ? ($current['action'] ?? null) : null,
        ]);
    }

    private static function write(int $cabinetId, string $tab, array $data): void
    {
        Cache::put(self::key($cabinetId, $tab), $data, 7200);
    }

    private static function key(int $cabinetId, string $tab): string
    {
        return 'wb-stock-history-job-'.$cabinetId.'-'.$tab;
    }
}
