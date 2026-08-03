<?php

namespace App\Support\Wb;

use App\Jobs\Wb\PriceCalc\ProcessPriceCalcJob;
use App\Models\JobStatus;

final class PriceCalcJobStatusPresenter
{
    public const OPERATION_SYNC = 'sync';

    public const OPERATION_IMPORT_EXCEL = 'import_excel';

    public const STAGE_QUEUED = 'queued';

    public const STAGE_IMPORTING = 'importing';

    public const STAGE_FETCHING = 'fetching';

    public const STAGE_CALCULATING = 'calculating';

    public const STAGE_SAVING = 'saving';

    public const STAGE_DONE = 'done';

    public const DUPLICATE_REJECTION_ERROR = 'Уже выполняется обработка. Дождитесь завершения.';

    /**
     * @return array<string, mixed>
     */
    public static function initialQueuedData(int $cabinetId, int $userId, string $operation): array
    {
        return [
            'cabinet_id' => $cabinetId,
            'user_id' => $userId,
            'operation' => $operation,
            'stage' => self::STAGE_QUEUED,
            'updated_rows' => null,
            'products_count' => null,
            'success_message' => null,
            'started_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function idle(): array
    {
        return [
            'status' => 'done',
            'error' => null,
            'stage' => null,
            'operation' => null,
            'updated_rows' => null,
            'products_count' => null,
            'success_message' => null,
            'started_at' => null,
            'progress_percent' => null,
            'status_label' => null,
            'status_detail' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromRecord(JobStatus $record): array
    {
        $data = is_array($record->data) ? $record->data : [];
        $stage = (string) ($data['stage'] ?? '');
        $status = (string) $record->status;
        $operation = (string) ($data['operation'] ?? '');

        return [
            'status' => $status,
            'error' => $record->error,
            'stage' => $stage !== '' ? $stage : null,
            'operation' => $operation !== '' ? $operation : null,
            'updated_rows' => isset($data['updated_rows']) ? (int) $data['updated_rows'] : null,
            'products_count' => isset($data['products_count']) ? (int) $data['products_count'] : null,
            'success_message' => isset($data['success_message']) ? (string) $data['success_message'] : null,
            'started_at' => $data['started_at'] ?? null,
            'progress_percent' => self::resolveProgressPercent($status, $stage, $operation),
            'status_label' => self::resolveStatusLabel($status, $stage, $operation, $data),
            'status_detail' => self::resolveStatusDetail($status, $stage, $operation, $data),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forCabinet(int $cabinetId): array
    {
        $record = JobStatus::query()
            ->where('job_name', ProcessPriceCalcJob::class)
            ->where('data->cabinet_id', $cabinetId)
            ->latest()
            ->first();

        if (! $record) {
            return self::idle();
        }

        return self::fromRecord($record);
    }

    public static function hasActiveJob(int $cabinetId): bool
    {
        return JobStatus::query()
            ->where('job_name', ProcessPriceCalcJob::class)
            ->where('data->cabinet_id', $cabinetId)
            ->where('status', 'processing')
            ->exists();
    }

    public static function markQueued(int $cabinetId, int $userId, string $operation): JobStatus
    {
        $existing = JobStatus::query()
            ->where('job_name', ProcessPriceCalcJob::class)
            ->where('data->cabinet_id', $cabinetId)
            ->latest()
            ->first();

        $payload = [
            'data' => self::initialQueuedData($cabinetId, $userId, $operation),
            'status' => 'processing',
            'error' => null,
            'updated_at' => now(),
        ];

        if ($existing) {
            $existing->update($payload);

            return $existing->fresh();
        }

        return JobStatus::create(array_merge($payload, [
            'job_name' => ProcessPriceCalcJob::class,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolveProgressPercent(string $status, string $stage, string $operation): int
    {
        if ($status === 'done') {
            return 100;
        }

        if ($status === 'failed') {
            return self::stageBaseProgress($stage, $operation);
        }

        return match ($stage) {
            self::STAGE_QUEUED => 8,
            self::STAGE_IMPORTING => 25,
            self::STAGE_FETCHING => $operation === self::OPERATION_SYNC ? 45 : 50,
            self::STAGE_CALCULATING => 75,
            self::STAGE_SAVING => 92,
            self::STAGE_DONE => 100,
            default => $status === 'processing' ? 10 : 0,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolveStatusLabel(string $status, string $stage, string $operation, array $data): ?string
    {
        if ($status === 'failed') {
            return $operation === self::OPERATION_SYNC
                ? 'Не удалось обновить список'
                : 'Не удалось завершить пересчёт';
        }

        if ($status === 'done') {
            return (string) ($data['success_message'] ?? ($operation === self::OPERATION_SYNC
                ? 'Список товаров обновлён'
                : 'Цены пересчитаны'));
        }

        return match ($stage) {
            self::STAGE_QUEUED => 'Скоро начнём',
            self::STAGE_IMPORTING => 'Загружаем Excel',
            self::STAGE_FETCHING => $operation === self::OPERATION_SYNC
                ? 'Обновляем список товаров'
                : 'Получаем данные',
            self::STAGE_CALCULATING => 'Считаем цены',
            self::STAGE_SAVING => 'Сохраняем',
            self::STAGE_DONE => 'Готово',
            default => $status === 'processing' ? 'Идёт обработка' : null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolveStatusDetail(string $status, string $stage, string $operation, array $data): ?string
    {
        if ($status !== 'processing') {
            return null;
        }

        return match ($stage) {
            self::STAGE_QUEUED => 'Запрос принят — обычно старт занимает несколько секунд.',
            self::STAGE_IMPORTING => 'Читаем файл и обновляем строки.',
            self::STAGE_FETCHING => $operation === self::OPERATION_SYNC
                ? 'Загружаем карточки товаров.'
                : 'Запрашиваем продажи, тарифы и статистику.',
            self::STAGE_CALCULATING => 'Считаем логистику и итоговые цены.',
            self::STAGE_SAVING => 'Почти готово — записываем результат.',
            default => null,
        };
    }

    private static function stageBaseProgress(string $stage, string $operation): int
    {
        return self::resolveProgressPercent('processing', $stage, $operation);
    }
}
