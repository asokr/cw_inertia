<?php

namespace App\Support;

use App\Models\JobStatus;

class ProfitabilityJobStatusPresenter
{
    public const DUPLICATE_REJECTION_ERROR = 'Отчёт уже выполняется, повторный запрос отклонён.';

    public const STAGE_QUEUED = 'queued';

    public const STAGE_PREPARING = 'preparing';

    public const STAGE_FETCHING = 'fetching';

    public const STAGE_ANALYZING = 'analyzing';

    public const STAGE_CALCULATING = 'calculating';

    public const STAGE_SAVING = 'saving';

    public const STAGE_DONE = 'done';

    /**
     * @return array<string, mixed>
     */
    public static function initialQueuedData(int $cabinetId, int $userId): array
    {
        return [
            'cabinet_id' => $cabinetId,
            'user_id' => $userId,
            'stage' => self::STAGE_QUEUED,
            'batch' => 0,
            'rows_loaded' => 0,
            'waiting_for_api' => false,
            'started_at' => now()->toIso8601String(),
        ];
    }

    public static function isBenignDuplicateFailure(?JobStatus $record): bool
    {
        return $record !== null
            && $record->status === 'failed'
            && $record->error === self::DUPLICATE_REJECTION_ERROR;
    }

    public static function clearBenignDuplicateFailure(JobStatus $record): void
    {
        if (! self::isBenignDuplicateFailure($record)) {
            return;
        }

        $data = is_array($record->data) ? $record->data : [];

        $record->update([
            'status' => 'done',
            'error' => null,
            'data' => array_merge($data, [
                'stage' => self::STAGE_DONE,
                'waiting_for_api' => false,
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromRecord(JobStatus $record): array
    {
        $data = is_array($record->data) ? $record->data : [];
        $stage = (string) ($data['stage'] ?? '');
        $status = (string) $record->status;

        return [
            'status' => $status,
            'error' => $record->error,
            'stage' => $stage !== '' ? $stage : null,
            'batch' => isset($data['batch']) ? (int) $data['batch'] : null,
            'rows_loaded' => isset($data['rows_loaded']) ? (int) $data['rows_loaded'] : null,
            'waiting_for_api' => (bool) ($data['waiting_for_api'] ?? false),
            'started_at' => $data['started_at'] ?? null,
            'progress_percent' => self::resolveProgressPercent($status, $stage, $data),
            'status_label' => self::resolveStatusLabel($status, $stage, $data),
            'status_detail' => self::resolveStatusDetail($status, $stage, $data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolveProgressPercent(string $status, string $stage, array $data): int
    {
        if ($status === 'done') {
            return 100;
        }

        if ($status === 'failed') {
            return self::stageBaseProgress($stage);
        }

        return match ($stage) {
            self::STAGE_QUEUED => 5,
            self::STAGE_PREPARING => 15,
            self::STAGE_FETCHING => min(55, 20 + max(0, (int) ($data['batch'] ?? 0)) * 4),
            self::STAGE_ANALYZING => 62,
            self::STAGE_CALCULATING => 78,
            self::STAGE_SAVING => 92,
            self::STAGE_DONE => 100,
            default => $status === 'processing' ? 8 : 0,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolveStatusLabel(string $status, string $stage, array $data): ?string
    {
        if ($status === 'failed') {
            return 'Не удалось подготовить отчёт';
        }

        if ($status === 'done') {
            return 'Отчёт готов';
        }

        return match ($stage) {
            self::STAGE_QUEUED => 'Скоро начнём',
            self::STAGE_PREPARING => 'Готовим данные',
            self::STAGE_FETCHING => ($data['waiting_for_api'] ?? false)
                ? 'Ждём данные от Wildberries'
                : 'Загружаем продажи и операции',
            self::STAGE_ANALYZING => 'Разбираем операции',
            self::STAGE_CALCULATING => 'Считаем прибыль по товарам',
            self::STAGE_SAVING => 'Сохраняем отчёт',
            self::STAGE_DONE => 'Отчёт готов',
            default => $status === 'processing' ? 'Готовим отчёт' : null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolveStatusDetail(string $status, string $stage, array $data): ?string
    {
        if ($status !== 'processing') {
            return null;
        }

        if ($stage === self::STAGE_QUEUED) {
            return 'Запрос принят — обычно старт занимает несколько секунд.';
        }

        if ($stage === self::STAGE_PREPARING) {
            return 'Проверяем кабинет и себестоимость из «Ценообразования».';
        }

        if ($stage === self::STAGE_FETCHING) {
            $rowsLoaded = (int) ($data['rows_loaded'] ?? 0);
            if ($rowsLoaded > 0) {
                return 'Уже загружено '.number_format($rowsLoaded, 0, ',', ' ').' операций';
            }

            return 'Получаем данные за выбранный период.';
        }

        if ($stage === self::STAGE_ANALYZING) {
            return 'Сортируем продажи, возвраты, логистику и прочие операции.';
        }

        if ($stage === self::STAGE_CALCULATING) {
            return 'Считаем себестоимость, маржу и рентабельность.';
        }

        if ($stage === self::STAGE_SAVING) {
            return 'Почти готово — записываем итог.';
        }

        return null;
    }

    private static function stageBaseProgress(string $stage): int
    {
        return match ($stage) {
            self::STAGE_QUEUED => 5,
            self::STAGE_PREPARING => 15,
            self::STAGE_FETCHING => 35,
            self::STAGE_ANALYZING => 62,
            self::STAGE_CALCULATING => 78,
            self::STAGE_SAVING => 92,
            default => 0,
        };
    }
}