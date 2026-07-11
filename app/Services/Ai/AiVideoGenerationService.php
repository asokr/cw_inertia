<?php

namespace App\Services\Ai;

use App\Models\AiVideoGeneration;
use App\Models\AiVideoGenerationTask;
use Illuminate\Support\Facades\DB;
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
    public function show(int $generationId, int $subscriberId): ?array
    {
        $generation = AiVideoGeneration::query()
            ->where('id', $generationId)
            ->where('subscriber_id', $subscriberId)
            ->with(['tasks' => fn ($query) => $query->orderByDesc('id')])
            ->first();

        if (! $generation) {
            return null;
        }

        return [
            'id' => $generation->id,
            'title' => $this->resolveTitle($generation),
            'created_at' => $generation->created_at?->toIso8601String(),
            'updated_at' => $generation->updated_at?->toIso8601String(),
            'tasks' => $generation->tasks
                ->map(fn (AiVideoGenerationTask $task) => $this->mapTaskForFrontend($task))
                ->values()
                ->all(),
        ];
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
        ?int $generationId,
        int $subscriberId,
        int $userId,
        string $prompt,
    ): AiVideoGeneration {
        if ($generationId !== null && $generationId > 0) {
            $existing = AiVideoGeneration::query()
                ->where('id', $generationId)
                ->where('subscriber_id', $subscriberId)
                ->first();

            if ($existing) {
                if ($existing->title === null || trim($existing->title) === '') {
                    $existing->update([
                        'title' => $this->titleFromPrompt($prompt),
                    ]);
                }

                return $existing->fresh();
            }
        }

        return $this->create($subscriberId, $userId, $this->titleFromPrompt($prompt));
    }

    public function findTaskByExternalId(string $requestId, int $subscriberId): ?AiVideoGenerationTask
    {
        return AiVideoGenerationTask::query()
            ->where('external_request_id', $requestId)
            ->where('subscriber_id', $subscriberId)
            ->latest('id')
            ->first();
    }

    public function delete(int $generationId, int $subscriberId): bool
    {
        $generation = AiVideoGeneration::query()
            ->where('id', $generationId)
            ->where('subscriber_id', $subscriberId)
            ->with('tasks')
            ->first();

        if (! $generation) {
            return false;
        }

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
        $task = AiVideoGenerationTask::query()->create([
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
        ]);

        $this->touchGeneration($generation);

        return $task;
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
            'title' => $this->resolveTitle($generation),
            'preview_url' => $previewUrl,
            'tasks_count' => $tasks->count(),
            'has_pending' => $hasPending,
            'created_at' => $generation->created_at?->toIso8601String(),
            'updated_at' => $generation->updated_at?->toIso8601String(),
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