<?php

namespace App\Jobs\Wb\PriceCalc;

use App\Models\JobStatus;
use App\Services\Subscriber\Wb\WbPriceCalculationV3Service;
use App\Support\Wb\PriceCalcJobStatusPresenter;
use App\Support\Wb\WbPriceCalcOperationGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessPriceCalcJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 2700;

    public int $tries = 1;

    public ?int $statusRecordId = null;

    public function __construct(
        public int $cabinetId,
        public int $userId,
        public string $operation,
        public ?string $storedFilePath = null,
    ) {
        $this->onQueue('price_calc');
    }

    public function handle(
        WbPriceCalculationV3Service $service,
        WbPriceCalcOperationGuard $operationGuard,
    ): void {
        if ($this->failIfAlreadyProcessing()) {
            return;
        }

        // Service methods resolve cabinets via Auth::id(); queue workers have no session.
        if ($this->userId > 0) {
            Auth::onceUsingId($this->userId);
        }

        $this->statusRecordId = $this->upsertStatusRecord();
        $this->updateStatusProgress(PriceCalcJobStatusPresenter::STAGE_QUEUED);

        $acquired = $operationGuard->acquire($this->cabinetId);
        if (! ($acquired['ok'] ?? false)) {
            $this->updateStatusFailed((string) ($acquired['message'] ?? PriceCalcJobStatusPresenter::DUPLICATE_REJECTION_ERROR));

            return;
        }

        try {
            $result = match ($this->operation) {
                PriceCalcJobStatusPresenter::OPERATION_SYNC => $service->runSyncForJob(
                    $this->cabinetId,
                    fn (string $stage, array $meta = []) => $this->updateStatusProgress($stage, $meta)
                ),
                PriceCalcJobStatusPresenter::OPERATION_IMPORT_EXCEL => $service->runImportExcelForJob(
                    $this->cabinetId,
                    (string) $this->storedFilePath,
                    fn (string $stage, array $meta = []) => $this->updateStatusProgress($stage, $meta)
                ),
                default => [
                    'success' => false,
                    'message' => 'Неизвестная операция',
                ],
            };

            if (! ($result['success'] ?? false)) {
                $message = (string) ($result['message'] ?? 'Не удалось выполнить операцию');
                if (! empty($result['cooldown'])) {
                    $operationGuard->setCooldownAfter429($this->cabinetId);
                }
                $this->updateStatusFailed($message);

                return;
            }

            $operationGuard->setCooldown(
                $this->cabinetId,
                WbPriceCalcOperationGuard::COOLDOWN_SECONDS
            );

            JobStatus::where('id', $this->statusRecordId)->update([
                'status' => 'done',
                'error' => null,
                'data' => $this->mergeStatusData([
                    'stage' => PriceCalcJobStatusPresenter::STAGE_DONE,
                    'updated_rows' => $result['updated_rows'] ?? null,
                    'products_count' => $result['products_count'] ?? null,
                    'success_message' => $result['success_message'] ?? null,
                ]),
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('[PriceCalcJob] failed', [
                'cabinet_id' => $this->cabinetId,
                'operation' => $this->operation,
                'error' => $exception->getMessage(),
            ]);
            $this->updateStatusFailed('Не удалось выполнить операцию. Попробуйте позже.');
            throw $exception;
        } finally {
            $operationGuard->release($this->cabinetId);
            $this->cleanupStoredFile();
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->updateStatusFailed('Не удалось выполнить операцию. Попробуйте позже.');
        $this->cleanupStoredFile();
        app(WbPriceCalcOperationGuard::class)->release($this->cabinetId);
    }

    private function failIfAlreadyProcessing(): bool
    {
        $processing = JobStatus::query()
            ->where('job_name', self::class)
            ->where('data->cabinet_id', $this->cabinetId)
            ->where('status', 'processing')
            ->get()
            ->filter(function (JobStatus $record): bool {
                $stage = $record->data['stage'] ?? null;

                // Allow the row we just marked as queued from the HTTP request.
                return $stage !== null && $stage !== PriceCalcJobStatusPresenter::STAGE_QUEUED;
            });

        if ($processing->isEmpty()) {
            return false;
        }

        Log::info('[PriceCalcJob] skipped — already processing', [
            'cabinet_id' => $this->cabinetId,
            'active_status_ids' => $processing->pluck('id')->all(),
        ]);

        return true;
    }

    private function upsertStatusRecord(): int
    {
        $threshold = now()->subMinutes(45);

        JobStatus::query()
            ->where('job_name', self::class)
            ->where('data->cabinet_id', $this->cabinetId)
            ->where('status', 'processing')
            ->where('updated_at', '<', $threshold)
            ->update([
                'status' => 'failed',
                'error' => 'Выполнение превысило ограничение по времени',
                'updated_at' => now(),
            ]);

        $existing = JobStatus::query()
            ->where('job_name', self::class)
            ->where('data->cabinet_id', $this->cabinetId)
            ->latest()
            ->first();

        if ($existing) {
            $data = PriceCalcJobStatusPresenter::initialQueuedData(
                $this->cabinetId,
                $this->userId,
                $this->operation
            );
            $startedAt = $existing->data['started_at'] ?? null;
            if (is_string($startedAt) && $startedAt !== '') {
                $data['started_at'] = $startedAt;
            }

            $existing->update([
                'data' => $data,
                'status' => 'processing',
                'error' => null,
                'updated_at' => now(),
            ]);

            return (int) $existing->id;
        }

        return (int) JobStatus::create([
            'job_name' => self::class,
            'data' => PriceCalcJobStatusPresenter::initialQueuedData(
                $this->cabinetId,
                $this->userId,
                $this->operation
            ),
            'status' => 'processing',
            'error' => null,
        ])->id;
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
            'status' => 'processing',
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
        $data = $record?->data ?? PriceCalcJobStatusPresenter::initialQueuedData(
            $this->cabinetId,
            $this->userId,
            $this->operation
        );

        return array_merge($data, $meta);
    }

    private function updateStatusFailed(string $message): void
    {
        if ($this->statusRecordId === null) {
            $existing = JobStatus::query()
                ->where('job_name', self::class)
                ->where('data->cabinet_id', $this->cabinetId)
                ->latest()
                ->first();
            $this->statusRecordId = $existing?->id;
        }

        if ($this->statusRecordId === null) {
            return;
        }

        JobStatus::where('id', $this->statusRecordId)->update([
            'status' => 'failed',
            'error' => $message,
            'data' => $this->mergeStatusData([]),
            'updated_at' => now(),
        ]);
    }

    private function cleanupStoredFile(): void
    {
        if ($this->storedFilePath === null || $this->storedFilePath === '') {
            return;
        }

        try {
            if (Storage::disk('local')->exists($this->storedFilePath)) {
                Storage::disk('local')->delete($this->storedFilePath);
            }
        } catch (Throwable) {
            // ignore cleanup errors
        }
    }
}
