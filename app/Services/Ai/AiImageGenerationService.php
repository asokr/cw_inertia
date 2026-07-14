<?php

namespace App\Services\Ai;

use App\Models\AiImageGeneration;
use App\Models\AiImageGenerationTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiImageGenerationService
{
    public function __construct(
        private readonly AiMediaStorageService $aiMediaStorageService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForSubscriber(int $subscriberId): array
    {
        $generations = AiImageGeneration::query()
            ->where('subscriber_id', $subscriberId)
            ->with(['tasks' => fn ($query) => $query->orderByDesc('id')])
            ->orderByDesc('updated_at')
            ->get();

        return $generations
            ->map(fn (AiImageGeneration $generation) => $this->mapGenerationSummary($generation))
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
    ): ?AiImageGeneration {
        $query = AiImageGeneration::query()
            ->where('uuid', $generationUuid)
            ->where('subscriber_id', $subscriberId);

        if ($withTasks) {
            $query->with(['tasks' => fn ($taskQuery) => $taskQuery->orderByDesc('id')]);
        }

        return $query->first();
    }

    public function create(int $subscriberId, int $userId, ?string $title = null): AiImageGeneration
    {
        return AiImageGeneration::query()->create([
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
    ): AiImageGeneration {
        if (filled($generationUuid)) {
            $existing = $this->findForSubscriberByUuid($generationUuid, $subscriberId);

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

    public function deleteByUuid(string $generationUuid, int $subscriberId): bool
    {
        $generation = $this->findForSubscriberByUuid($generationUuid, $subscriberId);

        if (! $generation) {
            return false;
        }

        $generation->load('tasks');

        DB::transaction(function () use ($generation): void {
            foreach ($generation->tasks as $task) {
                $this->aiMediaStorageService->deleteImageTaskMedia($task);
                $task->delete();
            }

            $generation->delete();
        });

        return true;
    }

    public function touchGeneration(AiImageGeneration $generation): void
    {
        $generation->touch();
    }

    public function hasStoredSourceImages(AiImageGeneration $generation): bool
    {
        return $generation->tasks()
            ->get()
            ->contains(function (AiImageGenerationTask $task): bool {
                $sourceImages = $task->source_images;

                return is_array($sourceImages) && $sourceImages !== [];
            });
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $sourceImages
     * @param  array<int, array<string, mixed>>|null  $resultImages
     */
    public function createTask(
        AiImageGeneration $generation,
        int $subscriberId,
        int $userId,
        string $taskType,
        string $prompt,
        int $imageVariants,
        string $resolution,
        ?string $aspectRatio,
        ?array $sourceImages,
        string $status,
        ?array $resultImages = null,
        ?string $model = null,
        ?string $errorMessage = null,
    ): AiImageGenerationTask {
        $task = AiImageGenerationTask::query()->create([
            'image_generation_id' => $generation->id,
            'subscriber_id' => $subscriberId,
            'user_id' => $userId,
            'task_type' => $taskType,
            'prompt' => $prompt,
            'image_variants' => $imageVariants,
            'resolution' => $resolution,
            'aspect_ratio' => $aspectRatio,
            'source_images' => $sourceImages,
            'status' => $status,
            'result_images' => $resultImages,
            'model' => $model,
            'error_message' => $errorMessage,
        ]);

        $this->touchGeneration($generation);

        return $task;
    }

    /**
     * @return array<string, mixed>
     */
    public function mapTaskForFrontend(AiImageGenerationTask $task): array
    {
        $sourceImages = is_array($task->source_images) ? $task->source_images : [];
        $resultImages = is_array($task->result_images) ? $task->result_images : [];
        $sourceUrls = array_values(array_filter(array_map(
            fn (?array $image): ?string => $this->resolveSourceImageUrl($image),
            $sourceImages,
        )));
        $resultUrls = $this->resolveResultImageUrls($resultImages);

        $frontendStatus = $task->status;
        if ($frontendStatus === AiImageGenerationTask::STATUS_FAILED) {
            $frontendStatus = 'error';
        }

        $mapped = [
            'id' => $task->id,
            'status' => $frontendStatus,
            'prompt' => $task->prompt,
            'task_type' => $task->task_type,
            'image_variants' => $task->image_variants,
            'resolution' => $task->resolution,
            'aspect_ratio' => $task->aspect_ratio,
            'error' => $task->error_message,
            'created_at' => $task->created_at?->toIso8601String(),
        ];

        if ($sourceUrls !== []) {
            $mapped['source_images'] = $sourceUrls;
            $mapped['image'] = $sourceUrls[0];
        }

        if ($resultUrls !== []) {
            $mapped['images'] = $resultUrls;
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapGenerationSummary(AiImageGeneration $generation): array
    {
        $tasks = $generation->tasks;
        $previewUrl = null;

        foreach ($tasks as $task) {
            if ($task->status === AiImageGenerationTask::STATUS_DONE) {
                $resultImages = is_array($task->result_images) ? $task->result_images : [];
                $urls = $this->resolveResultImageUrls($resultImages);
                $previewUrl = $urls[0] ?? null;

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

        return [
            'id' => $generation->id,
            'uuid' => $generation->uuid,
            'title' => $this->resolveTitle($generation),
            'preview_url' => $previewUrl,
            'tasks_count' => $tasks->count(),
            'has_pending' => false,
            'created_at' => $generation->created_at?->toIso8601String(),
            'updated_at' => $generation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapGenerationDetail(AiImageGeneration $generation): array
    {
        return [
            'id' => $generation->id,
            'uuid' => $generation->uuid,
            'title' => $this->resolveTitle($generation),
            'created_at' => $generation->created_at?->toIso8601String(),
            'updated_at' => $generation->updated_at?->toIso8601String(),
            'tasks' => $generation->tasks
                ->map(fn (AiImageGenerationTask $task) => $this->mapTaskForFrontend($task))
                ->values()
                ->all(),
        ];
    }

    private function resolveTitle(AiImageGeneration $generation): string
    {
        $title = trim((string) ($generation->title ?? ''));

        if ($title !== '') {
            return $title;
        }

        $firstTask = $generation->relationLoaded('tasks')
            ? $generation->tasks->sortBy('id')->first()
            : $generation->tasks()->orderBy('id')->first();

        if ($firstTask instanceof AiImageGenerationTask) {
            return $this->titleFromPrompt($firstTask->prompt);
        }

        return 'Генерация от ' . ($generation->created_at?->format('d.m.Y H:i') ?? now()->format('d.m.Y H:i'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $resultImages
     * @return array<int, string>
     */
    private function resolveResultImageUrls(array $resultImages): array
    {
        return array_values(array_filter(array_map(
            fn (array $image): ?string => $this->resolveSourceImageUrl($image),
            $resultImages,
        )));
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