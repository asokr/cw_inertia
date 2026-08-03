<?php

namespace App\Services\Ai;

use App\Models\AiVideoGeneration;
use App\Models\AiVideoGenerationTask;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiVideoGenerationService
{
    public function __construct(
        private readonly AiMediaStorageService $aiMediaStorageService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForSubscriber(int $subscriberId): array
    {
        $generations = AiVideoGeneration::query()
            ->where('subscriber_id', $subscriberId)
            ->with(['tasks' => fn ($query) => $query->orderByDesc('id')])
            ->orderByDesc('updated_at')
            ->get();

        return $generations
            ->map(fn (AiVideoGeneration $generation) => $this->mapGenerationSummary($generation))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function showByUuid(string $generationUuid, int $subscriberId): ?array
    {
        $generation = $this->findForSubscriberByUuid($generationUuid, $subscriberId, withTasks: true);

        if (! $generation) {
            return null;
        }

        return $this->mapGenerationDetail($generation);
    }

    public function findForSubscriberByUuid(
        string $generationUuid,
        int $subscriberId,
        bool $withTasks = false,
    ): ?AiVideoGeneration {
        $query = AiVideoGeneration::query()
            ->where('uuid', $generationUuid)
            ->where('subscriber_id', $subscriberId);

        if ($withTasks) {
            $query->with(['tasks' => fn ($taskQuery) => $taskQuery->orderByDesc('id')]);
        }

        return $query->first();
    }

    public function create(int $subscriberId, int $userId, ?string $title = null): AiVideoGeneration
    {
        return AiVideoGeneration::query()->create([
            'subscriber_id' => $subscriberId,
            'user_id' => $userId,
            'title' => $this->normalizeTitle($title),
        ]);
    }

    public function resolveForStart(
        ?string $generationUuid,
        int $subscriberId,
        int $userId,
        string $prompt,
    ): AiVideoGeneration {
        if (filled($generationUuid)) {
            $existing = $this->findForSubscriberByUuid($generationUuid, $subscriberId);

            if ($existing) {
                if ($existing->title === null || trim($existing->title) === '') {
                    $existing->update([
                        'title' => $this->titleFromPrompt($prompt),
                    ]);
                }

                // fresh() may return null if the row was deleted between select and refresh.
                return $existing->fresh() ?? $existing;
            }
        }

        return $this->create($subscriberId, $userId, $this->titleFromPrompt($prompt));
    }

    /**
     * Re-check that the generation row still exists before writing a task.
     * Long AI requests can outlive the session if the user deletes it mid-flight.
     */
    public function ensureGenerationForWrite(
        AiVideoGeneration $generation,
        int $subscriberId,
        int $userId,
        ?string $prompt = null,
    ): AiVideoGeneration {
        $existing = AiVideoGeneration::query()
            ->whereKey($generation->id)
            ->where('subscriber_id', $subscriberId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $preferredUuid = filled($generation->uuid) ? (string) $generation->uuid : null;
        $uuidAvailable = $preferredUuid
            && ! AiVideoGeneration::query()->where('uuid', $preferredUuid)->exists();

        Log::warning('AI video generation missing before task create; recreating session', [
            'missing_generation_id' => $generation->id,
            'generation_uuid' => $preferredUuid,
            'subscriber_id' => $subscriberId,
            'user_id' => $userId,
            'reuse_uuid' => $uuidAvailable,
        ]);

        $title = $this->normalizeTitle(
            $prompt !== null && trim($prompt) !== ''
                ? $this->titleFromPrompt($prompt)
                : ($generation->title ?? null)
        );

        return AiVideoGeneration::query()->create(array_filter([
            'uuid' => $uuidAvailable ? $preferredUuid : null,
            'subscriber_id' => $subscriberId,
            'user_id' => $userId,
            'title' => $title,
        ], static fn ($value) => $value !== null));
    }

    public function findTaskByExternalId(string $requestId, int $subscriberId): ?AiVideoGenerationTask
    {
        return AiVideoGenerationTask::query()
            ->where('external_request_id', $requestId)
            ->where('subscriber_id', $subscriberId)
            ->latest('id')
            ->first();
    }

    public function deleteByUuid(string $generationUuid, int $subscriberId): bool
    {
        $generation = $this->findForSubscriberByUuid($generationUuid, $subscriberId);

        if (! $generation) {
            return false;
        }

        $generation->load('tasks');

        DB::transaction(function () use ($generation): void {
            foreach ($generation->tasks as $task) {
                $this->aiMediaStorageService->deleteTaskMedia($task);
                $task->delete();
            }

            $generation->delete();
        });

        return true;
    }

    public function touchGeneration(AiVideoGeneration $generation): void
    {
        $generation->touch();
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $sourceImages
     */
    public function createTask(
        AiVideoGeneration $generation,
        int $subscriberId,
        int $userId,
        string $taskType,
        string $prompt,
        int $duration,
        string $resolution,
        ?string $aspectRatio,
        ?array $sourceImages,
        string $status,
        ?string $externalRequestId = null,
        ?string $model = null,
        ?string $errorMessage = null,
    ): AiVideoGenerationTask {
        $generation = $this->ensureGenerationForWrite($generation, $subscriberId, $userId, $prompt);

        $attributes = [
            'video_generation_id' => $generation->id,
            'subscriber_id' => $subscriberId,
            'user_id' => $userId,
            'external_request_id' => $externalRequestId,
            'task_type' => $taskType,
            'prompt' => $prompt,
            'duration' => $duration,
            'resolution' => $resolution,
            'aspect_ratio' => $aspectRatio,
            'source_images' => $sourceImages,
            'status' => $status,
            'model' => $model,
            'error_message' => $errorMessage,
        ];

        try {
            $task = AiVideoGenerationTask::query()->create($attributes);
        } catch (QueryException $exception) {
            if (! $this->isGenerationForeignKeyViolation($exception)) {
                throw $exception;
            }

            Log::warning('AI video task insert hit missing generation FK; retrying once', [
                'missing_generation_id' => $generation->id,
                'generation_uuid' => $generation->uuid,
                'subscriber_id' => $subscriberId,
                'user_id' => $userId,
            ]);

            $stale = new AiVideoGeneration();
            $stale->forceFill([
                'id' => 0,
                'uuid' => $generation->uuid,
                'subscriber_id' => $subscriberId,
                'user_id' => $userId,
                'title' => $generation->title,
            ]);
            $stale->syncOriginal();

            $generation = $this->ensureGenerationForWrite($stale, $subscriberId, $userId, $prompt);
            $attributes['video_generation_id'] = $generation->id;
            $task = AiVideoGenerationTask::query()->create($attributes);
        }

        $this->touchGeneration($generation);
        $task->setRelation('generation', $generation);

        return $task;
    }

    private function isGenerationForeignKeyViolation(QueryException $exception): bool
    {
        if ((string) $exception->getCode() !== '23000') {
            return false;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'video_generation_id')
            || str_contains($message, 'ai_video_generation_tasks_video_generation_id_foreign');
    }

    /**
     * @return array<string, mixed>
     */
    public function mapTaskForFrontend(AiVideoGenerationTask $task): array
    {
        $sourceImages = is_array($task->source_images) ? $task->source_images : [];
        $firstImage = $this->resolveSourceImageUrl($sourceImages[0] ?? null);
        $resultVideo = is_array($task->result_video) ? $task->result_video : null;
        $videoUrl = $this->resolveResultVideoUrl($resultVideo);

        $frontendStatus = $task->status;
        if ($frontendStatus === AiVideoGenerationTask::STATUS_FAILED) {
            $frontendStatus = 'error';
        }

        $mapped = [
            'id' => $task->id,
            'request_id' => $task->external_request_id,
            'status' => $frontendStatus,
            'prompt' => $task->prompt,
            'task_type' => $task->task_type,
            'duration' => $task->duration,
            'resolution' => $task->resolution,
            'aspect_ratio' => $task->aspect_ratio,
            'error' => $task->error_message,
            'created_at' => $task->created_at?->toIso8601String(),
        ];

        if ($firstImage) {
            $mapped['image'] = $firstImage;
        }

        if (count($sourceImages) > 1 || $task->task_type === 'generate_video_from_scene') {
            $mapped['images'] = array_values(array_filter(array_map(
                fn (?array $image): ?string => $this->resolveSourceImageUrl($image),
                $sourceImages,
            )));
        }

        if ($videoUrl) {
            $mapped['video'] = [
                'url' => $videoUrl,
                'path' => $resultVideo['path'] ?? null,
                'duration' => $resultVideo['duration'] ?? null,
            ];
        }

        return $mapped;
    }

    public function buildDoneStatusResponse(AiVideoGenerationTask $task): array
    {
        $resultVideo = is_array($task->result_video) ? $task->result_video : null;
        $videoUrl = $this->resolveResultVideoUrl($resultVideo);

        return [
            'success' => true,
            'messages' => ['Видео готово'],
            'data' => [
                'request_id' => $task->external_request_id,
                'status' => 'done',
                'video' => [
                    'url' => (string) ($videoUrl ?? ''),
                    'path' => (string) ($resultVideo['path'] ?? ''),
                    'duration' => $resultVideo['duration'] ?? null,
                ],
                'model' => $task->model,
                'generation_id' => $task->video_generation_id,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapGenerationSummary(AiVideoGeneration $generation): array
    {
        $tasks = $generation->tasks;
        $previewUrl = null;

        foreach ($tasks as $task) {
            if ($task->status === AiVideoGenerationTask::STATUS_DONE) {
                $previewUrl = $this->resolveResultVideoUrl(is_array($task->result_video) ? $task->result_video : null);

                if ($previewUrl) {
                    break;
                }
            }
        }

        if (! $previewUrl) {
            foreach ($tasks as $task) {
                $sourceImages = is_array($task->source_images) ? $task->source_images : [];
                $previewUrl = $this->resolveSourceImageUrl($sourceImages[0] ?? null);

                if ($previewUrl) {
                    break;
                }
            }
        }

        $hasPending = $tasks->contains(
            fn (AiVideoGenerationTask $task) => $task->status === AiVideoGenerationTask::STATUS_PENDING,
        );

        return [
            'id' => $generation->id,
            'uuid' => $generation->uuid,
            'title' => $this->resolveTitle($generation),
            'preview_url' => $previewUrl,
            'tasks_count' => $tasks->count(),
            'has_pending' => $hasPending,
            'created_at' => $generation->created_at?->toIso8601String(),
            'updated_at' => $generation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapGenerationDetail(AiVideoGeneration $generation): array
    {
        return [
            'id' => $generation->id,
            'uuid' => $generation->uuid,
            'title' => $this->resolveTitle($generation),
            'created_at' => $generation->created_at?->toIso8601String(),
            'updated_at' => $generation->updated_at?->toIso8601String(),
            'tasks' => $generation->tasks
                ->map(fn (AiVideoGenerationTask $task) => $this->mapTaskForFrontend($task))
                ->values()
                ->all(),
        ];
    }

    private function resolveTitle(AiVideoGeneration $generation): string
    {
        $title = trim((string) ($generation->title ?? ''));

        if ($title !== '') {
            return $title;
        }

        $firstTask = $generation->relationLoaded('tasks')
            ? $generation->tasks->sortBy('id')->first()
            : $generation->tasks()->orderBy('id')->first();

        if ($firstTask instanceof AiVideoGenerationTask) {
            return $this->titleFromPrompt($firstTask->prompt);
        }

        return 'Генерация от ' . ($generation->created_at?->format('d.m.Y H:i') ?? now()->format('d.m.Y H:i'));
    }

    /**
     * @param  array<string, mixed>|null  $resultVideo
     */
    private function resolveResultVideoUrl(?array $resultVideo): ?string
    {
        if (! is_array($resultVideo)) {
            return null;
        }

        return $this->aiMediaStorageService->resolvePanelMediaUrl(
            url: (string) ($resultVideo['url'] ?? $resultVideo['url_preview'] ?? $resultVideo['signed_url'] ?? ''),
            path: (string) ($resultVideo['path'] ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>|null  $sourceImage
     */
    private function resolveSourceImageUrl(?array $sourceImage): ?string
    {
        if (! is_array($sourceImage)) {
            return null;
        }

        return $this->aiMediaStorageService->resolvePanelMediaUrl(
            url: (string) ($sourceImage['url_preview'] ?? $sourceImage['signed_url'] ?? $sourceImage['url'] ?? ''),
            path: (string) ($sourceImage['path'] ?? ''),
        );
    }

    private function titleFromPrompt(string $prompt): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $prompt) ?? '');

        if ($normalized === '') {
            return 'Новая генерация';
        }

        return Str::limit($normalized, 60, '…');
    }

    private function normalizeTitle(?string $title): ?string
    {
        $normalized = trim((string) $title);

        return $normalized !== '' ? Str::limit($normalized, 120, '') : null;
    }
}