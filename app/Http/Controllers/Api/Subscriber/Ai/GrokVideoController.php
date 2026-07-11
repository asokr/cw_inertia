<?php

namespace App\Http\Controllers\Api\Subscriber\Ai;

use Throwable;
use RuntimeException;
use App\Enums\AiTaskType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\AiVideoGenerationTask;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Services\Grok\GrokVideoApiClient;
use App\Services\Ai\AiMediaStorageService;
use App\Services\Ai\AiVideoGenerationService;
use App\Models\Subscribers\SubscribersSubscriptions;

class GrokVideoController extends Controller
{
    private const PUBLIC_ERROR_MESSAGE = 'Ошибка при работе. Попробуйте позже.';
    private const HIGH_DEMAND_PUBLIC_ERROR_MESSAGE = 'ИИ временно перегружен, попробуйте позже.';
    private const MODERATION_PUBLIC_ERROR_MESSAGE = 'Видео не прошло модерацию. Измените запрос и попробуйте снова. При ограничении по контенту лимиты списываются, будьте аккуратнее.';
    private const MAX_INPUT_IMAGE_BYTES = 10485760;

    public function __construct(
        private readonly GrokVideoApiClient $grokVideoApiClient,
        private readonly AiMediaStorageService $aiMediaStorageService,
        private readonly AiVideoGenerationService $aiVideoGenerationService,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'task_type' => 'required|string|in:' . implode(',', [
                AiTaskType::GENERATE_VIDEO->value,
                AiTaskType::GENERATE_VIDEO_FROM_IMAGE->value,
            ]),
            'prompt' => 'required|string|max:4000',
            'duration' => 'nullable|integer|min:1|max:15',
            'resolution' => 'nullable|string',
            'aspect_ratio' => 'nullable|string',
            'aspectRatio' => 'nullable|string',
            'image' => 'nullable|string',
            'generation_id' => 'nullable|integer|min:1',
        ], [
            'task_type.required' => 'Не указан тип задачи',
            'task_type.in' => 'Указан недопустимый тип задачи',
            'prompt.required' => 'Не передан prompt',
            'duration.min' => 'Минимальная длительность видео 1 секунда',
            'duration.max' => 'Максимальная длительность видео 15 секунд',
        ]);

        $validator->after(function ($validator) use ($request) {
            $taskType = (string) $request->input('task_type');
            $resolution = $this->resolveResolution((string) $request->input('resolution', '480p'));
            $aspectRatio = $this->resolveAspectRatio($this->getRequestedAspectRatio($request));

            if (! in_array($resolution, ['480p', '720p'], true)) {
                $validator->errors()->add('resolution', 'Допустимые resolution: 480p, 720p');
            }

            if (
                $taskType === AiTaskType::GENERATE_VIDEO->value
                && ! in_array($aspectRatio, ['1:1', '16:9', '9:16', '4:3', '3:4', '3:2', '2:3'], true)
            ) {
                $validator->errors()->add('aspect_ratio', 'Допустимые aspect_ratio: 1:1, 16:9, 9:16, 4:3, 3:4, 3:2, 2:3');
            }

            if ($taskType === AiTaskType::GENERATE_VIDEO_FROM_IMAGE->value) {
                if (trim((string) $request->input('image', '')) === '') {
                    $validator->errors()->add('image', 'Для генерации видео из фото нужно передать image');
                }

                $imageError = $this->validateInputImageSize((string) $request->input('image', ''));
                if ($imageError !== null) {
                    $validator->errors()->add('image', $imageError);
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $user = auth()->user();
        $userId = (int) ($user?->id ?? 0);
        $subscriberId = (int) data_get($user, 'subscriber.id');

        if ($userId <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Пользователь не авторизован'],
            ], 401);
        }

        /** @var SubscribersSubscriptions|null $subscription */
        $subscription = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriberId,
            'status' => 1,
        ])->first();

        if (! $subscription) {
            return response()->json([
                'success' => false,
                'messages' => ['Активная подписка не найдена'],
            ], 200);
        }

        $taskType = (string) $request->input('task_type');
        $resolution = $this->resolveResolution((string) $request->input('resolution', '480p'));
        $aspectRatio = $taskType === AiTaskType::GENERATE_VIDEO->value
            ? $this->resolveAspectRatio($this->getRequestedAspectRatio($request))
            : null;
        $duration = (int) ($request->input('duration') ?? 1);
        $requiredVideoLimit = $this->resolveVideoLimitCost($duration, $resolution);

        if (! $this->hasEnoughLimit($subscription, 'ai_video_query', $requiredVideoLimit)) {
            return response()->json([
                'success' => false,
                'messages' => ['Недостаточно лимита AI_VIDEO_QUERY. Требуется: ' . $requiredVideoLimit],
            ], 402);
        }

        $prompt = trim((string) $request->input('prompt', ''));
        $sourceImageMeta = null;
        $imageInputForProvider = '';

        if ($taskType === AiTaskType::GENERATE_VIDEO_FROM_IMAGE->value) {
            try {
                $rawImageInput = (string) $request->input('image', '');
                $sourceImageMeta = $this->aiMediaStorageService->storeImageAndGetSignedUrl(
                    $rawImageInput,
                    $userId
                );
                $imageInputForProvider = trim($rawImageInput);

                if ($imageInputForProvider === '') {
                    throw new RuntimeException('Изображение не передано');
                }
            } catch (Throwable $exception) {
                return response()->json([
                    'success' => false,
                    'messages' => [$exception->getMessage()],
                ], 200);
            }
        }

        $generation = $this->aiVideoGenerationService->resolveForStart(
            $request->filled('generation_id') ? (int) $request->input('generation_id') : null,
            $subscriberId,
            $userId,
            $prompt,
        );

        $options = [
            'duration' => $request->input('duration'),
            'resolution' => $resolution,
            'image' => $imageInputForProvider,
        ];

        if (is_string($aspectRatio) && $aspectRatio !== '') {
            $options['aspect_ratio'] = $aspectRatio;
        }

        try {
            $response = $this->grokVideoApiClient->startGeneration(
                taskType: $taskType,
                prompt: $prompt,
                options: $options
            );

            if (! ($response['success'] ?? false)) {
                $message = (string) (($response['messages'][0] ?? null) ?: 'Ошибка Grok API');
                $statusCode = (int) ($response['status'] ?? 503);
                $publicMessage = $this->resolvePublicErrorMessage($message, $statusCode);
                $isModerationError = $this->isModerationError($message);

                $taskRecord = $this->aiVideoGenerationService->createTask(
                    generation: $generation,
                    subscriberId: $subscriberId,
                    userId: $userId,
                    taskType: $taskType,
                    prompt: $prompt,
                    duration: $duration,
                    resolution: $resolution,
                    aspectRatio: $aspectRatio,
                    sourceImages: $sourceImageMeta ? [$sourceImageMeta] : null,
                    status: $isModerationError
                        ? AiVideoGenerationTask::STATUS_FILTERED
                        : AiVideoGenerationTask::STATUS_FAILED,
                    externalRequestId: null,
                    model: (string) data_get($response, 'data.model', config('services.grok.video_model')),
                    errorMessage: $message,
                );

                if ($isModerationError) {
                    $this->consumeVideoLimitOnce($taskRecord, $subscription);

                    return response()->json([
                        'success' => false,
                        'messages' => [$publicMessage],
                        'meta' => [
                            'limits' => $this->getLimits($subscription->fresh()),
                        ],
                    ], 200);
                }

                return response()->json([
                    'success' => false,
                    'messages' => [$publicMessage],
                ], 200);
            }

            $requestId = trim((string) data_get($response, 'data.request_id', ''));
            if ($requestId === '') {
                return response()->json([
                    'success' => false,
                    'messages' => ['Grok API не вернул request_id'],
                ], 200);
            }

            $this->aiVideoGenerationService->createTask(
                generation: $generation,
                subscriberId: $subscriberId,
                userId: $userId,
                taskType: $taskType,
                prompt: $prompt,
                duration: $duration,
                resolution: $resolution,
                aspectRatio: $aspectRatio,
                sourceImages: $sourceImageMeta ? [$sourceImageMeta] : null,
                status: AiVideoGenerationTask::STATUS_PENDING,
                externalRequestId: $requestId,
                model: (string) data_get($response, 'data.model', config('services.grok.video_model')),
            );

            return response()->json([
                'success' => true,
                'messages' => ['Задача генерации видео создана'],
                'data' => [
                    'request_id' => $requestId,
                    'status' => 'pending',
                    'generation_id' => $generation->id,
                ],
            ], 200);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'messages' => [$this->resolvePublicErrorMessage($exception->getMessage(), 500)],
            ], 200);
        }
    }

    public function referenceStart(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'task_type' => 'nullable|string|in:' . AiTaskType::GENERATE_VIDEO_FROM_IMAGE->value,
            'prompt' => 'required|string|max:4000',
            'duration' => 'nullable|integer|min:1|max:10',
            'resolution' => 'nullable|string',
            'images' => 'required|array|min:1|max:7',
            'images.*' => 'required|string',
            'generation_id' => 'nullable|integer|min:1',
        ], [
            'task_type.in' => 'Для этого endpoint допустим только task_type=generate_video_from_image',
            'prompt.required' => 'Не передан prompt',
            'duration.min' => 'Минимальная длительность видео 1 секунда',
            'duration.max' => 'Максимальная длительность видео 10 секунд для режима reference-to-video',
            'images.required' => 'Для генерации сцены нужно передать images',
            'images.array' => 'Поле images должно быть массивом',
            'images.min' => 'Нужно передать хотя бы одно изображение',
            'images.max' => 'Можно передать не более 7 изображений',
        ]);

        $validator->after(function ($validator) use ($request) {
            $resolution = $this->resolveResolution((string) $request->input('resolution', '480p'));
            if (! in_array($resolution, ['480p', '720p'], true)) {
                $validator->errors()->add('resolution', 'Допустимые resolution: 480p, 720p');
            }

            $images = $this->normalizeImagesInput($request->input('images', []));
            if ($images === []) {
                $validator->errors()->add('images', 'Для генерации сцены нужно передать images');

                return;
            }

            foreach ($images as $index => $imageInput) {
                $imageFormatError = $this->validateInputImageFormat($imageInput);
                if ($imageFormatError !== null) {
                    $validator->errors()->add('images.' . $index, $imageFormatError);
                    continue;
                }

                $imageSizeError = $this->validateInputImageSize($imageInput);
                if ($imageSizeError !== null) {
                    $validator->errors()->add('images.' . $index, $imageSizeError);
                }
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $user = auth()->user();
        $userId = (int) ($user?->id ?? 0);
        $subscriberId = (int) data_get($user, 'subscriber.id');

        if ($userId <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Пользователь не авторизован'],
            ], 401);
        }

        /** @var SubscribersSubscriptions|null $subscription */
        $subscription = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriberId,
            'status' => 1,
        ])->first();

        if (! $subscription) {
            return response()->json([
                'success' => false,
                'messages' => ['Активная подписка не найдена'],
            ], 200);
        }

        $storedTaskType = 'generate_video_from_scene';
        $providerTaskType = AiTaskType::GENERATE_VIDEO_FROM_IMAGE->value;
        $resolution = $this->resolveResolution((string) $request->input('resolution', '480p'));
        $duration = (int) ($request->input('duration') ?? 1);
        $requiredVideoLimit = $this->resolveVideoLimitCost($duration, $resolution);

        if (! $this->hasEnoughLimit($subscription, 'ai_video_query', $requiredVideoLimit)) {
            return response()->json([
                'success' => false,
                'messages' => ['Недостаточно лимита AI_VIDEO_QUERY. Требуется: ' . $requiredVideoLimit],
            ], 402);
        }

        $prompt = trim((string) $request->input('prompt', ''));
        $generation = $this->aiVideoGenerationService->resolveForStart(
            $request->filled('generation_id') ? (int) $request->input('generation_id') : null,
            $subscriberId,
            $userId,
            $prompt,
        );
        $referenceImagesForProvider = $this->normalizeImagesInput($request->input('images', []));
        $sourceImagesMeta = [];

        try {
            foreach ($referenceImagesForProvider as $imageInput) {
                $sourceImagesMeta[] = $this->aiMediaStorageService->storeImageAndGetSignedUrl($imageInput, $userId);
            }
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'messages' => [$exception->getMessage()],
            ], 200);
        }

        $options = [
            'duration' => $request->input('duration'),
            'resolution' => $resolution,
            'reference_images' => $referenceImagesForProvider,
        ];

        try {
            $response = $this->grokVideoApiClient->startGeneration(
                taskType: $providerTaskType,
                prompt: $prompt,
                options: $options
            );

            if (! ($response['success'] ?? false)) {
                $message = (string) (($response['messages'][0] ?? null) ?: 'Ошибка Grok API');
                $statusCode = (int) ($response['status'] ?? 503);
                $publicMessage = $this->resolvePublicErrorMessage($message, $statusCode);
                $isModerationError = $this->isModerationError($message);

                $taskRecord = $this->aiVideoGenerationService->createTask(
                    generation: $generation,
                    subscriberId: $subscriberId,
                    userId: $userId,
                    taskType: $storedTaskType,
                    prompt: $prompt,
                    duration: $duration,
                    resolution: $resolution,
                    aspectRatio: null,
                    sourceImages: $sourceImagesMeta !== [] ? $sourceImagesMeta : null,
                    status: $isModerationError
                        ? AiVideoGenerationTask::STATUS_FILTERED
                        : AiVideoGenerationTask::STATUS_FAILED,
                    externalRequestId: null,
                    model: (string) data_get($response, 'data.model', config('services.grok.video_model')),
                    errorMessage: $message,
                );

                if ($isModerationError) {
                    $this->consumeVideoLimitOnce($taskRecord, $subscription);

                    return response()->json([
                        'success' => false,
                        'messages' => [$publicMessage],
                        'meta' => [
                            'limits' => $this->getLimits($subscription->fresh()),
                        ],
                    ], 200);
                }

                return response()->json([
                    'success' => false,
                    'messages' => [$publicMessage],
                ], 200);
            }

            $requestId = trim((string) data_get($response, 'data.request_id', ''));
            if ($requestId === '') {
                return response()->json([
                    'success' => false,
                    'messages' => ['Grok API не вернул request_id'],
                ], 200);
            }

            $this->aiVideoGenerationService->createTask(
                generation: $generation,
                subscriberId: $subscriberId,
                userId: $userId,
                taskType: $storedTaskType,
                prompt: $prompt,
                duration: $duration,
                resolution: $resolution,
                aspectRatio: null,
                sourceImages: $sourceImagesMeta !== [] ? $sourceImagesMeta : null,
                status: AiVideoGenerationTask::STATUS_PENDING,
                externalRequestId: $requestId,
                model: (string) data_get($response, 'data.model', config('services.grok.video_model')),
            );

            return response()->json([
                'success' => true,
                'messages' => ['Задача генерации видео создана'],
                'data' => [
                    'request_id' => $requestId,
                    'status' => 'pending',
                    'generation_id' => $generation->id,
                ],
            ], 200);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'messages' => [$this->resolvePublicErrorMessage($exception->getMessage(), 500)],
            ], 200);
        }
    }

    public function status(string $requestId): JsonResponse
    {
        $user = auth()->user();
        $userId = (int) ($user?->id ?? 0);
        $subscriberId = (int) data_get($user, 'subscriber.id');

        if ($userId <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Пользователь не авторизован'],
            ], 401);
        }

        /** @var SubscribersSubscriptions|null $subscription */
        $subscription = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriberId,
            'status' => 1,
        ])->first();

        if (! $subscription) {
            return response()->json([
                'success' => false,
                'messages' => ['Активная подписка не найдена'],
            ], 200);
        }

        $taskRecord = $this->aiVideoGenerationService->findTaskByExternalId($requestId, $subscriberId);

        if (! $taskRecord) {
            return response()->json([
                'success' => false,
                'messages' => ['Задача генерации видео не найдена'],
            ], 404);
        }

        if ($taskRecord->isTerminal()) {
            if ($taskRecord->status === AiVideoGenerationTask::STATUS_DONE) {
                $limits = $this->getLimits($subscription->fresh());

                return response()->json(array_merge(
                    $this->aiVideoGenerationService->buildDoneStatusResponse($taskRecord),
                    ['meta' => ['limits' => $limits]],
                ), 200);
            }

            $frontendStatus = $taskRecord->status === AiVideoGenerationTask::STATUS_FAILED
                ? 'error'
                : $taskRecord->status;

            return response()->json([
                'success' => $taskRecord->status !== AiVideoGenerationTask::STATUS_FAILED
                    && $taskRecord->status !== AiVideoGenerationTask::STATUS_EXPIRED,
                'messages' => [$this->resolvePublicErrorMessage($taskRecord->error_message, 200)],
                'data' => [
                    'request_id' => $requestId,
                    'status' => $frontendStatus,
                    'generation_id' => $taskRecord->video_generation_id,
                ],
            ], 200);
        }

        $response = $this->grokVideoApiClient->getGeneration($requestId);

        if (! ($response['success'] ?? false)) {
            $message = (string) (($response['messages'][0] ?? null) ?: 'Ошибка Grok API');
            $statusCode = (int) ($response['status'] ?? 503);

            $isModerationError = $this->isModerationError($message);
            $generationStatus = $isModerationError
                ? AiVideoGenerationTask::STATUS_FILTERED
                : AiVideoGenerationTask::STATUS_FAILED;

            $taskRecord->update([
                'model' => (string) data_get($response, 'data.model', $taskRecord->model ?? config('services.grok.video_model')),
                'status' => $generationStatus,
                'error_message' => $message,
            ]);
            $this->aiVideoGenerationService->touchGeneration($taskRecord->generation);

            if ($isModerationError) {
                $this->consumeVideoLimitOnce($taskRecord, $subscription);

                return response()->json([
                    'success' => false,
                    'messages' => [$this->resolvePublicErrorMessage($message, $statusCode)],
                    'data' => [
                        'request_id' => $requestId,
                        'status' => 'filtered_by_moderation',
                        'generation_id' => $taskRecord->video_generation_id,
                    ],
                    'meta' => [
                        'limits' => $this->getLimits($subscription->fresh()),
                    ],
                ], 200);
            }

            return response()->json([
                'success' => false,
                'messages' => [$this->resolvePublicErrorMessage($message, $statusCode)],
            ], 200);
        }

        $status = mb_strtolower(trim((string) data_get($response, 'data.status', 'pending')));

        if ($status === 'pending') {
            $taskRecord->update([
                'model' => (string) data_get($response, 'data.model', $taskRecord->model ?? config('services.grok.video_model')),
                'status' => AiVideoGenerationTask::STATUS_PENDING,
                'error_message' => null,
            ]);

            return response()->json([
                'success' => true,
                'messages' => ['Видео ещё генерируется'],
                'data' => [
                    'request_id' => $requestId,
                    'status' => 'pending',
                    'generation_id' => $taskRecord->video_generation_id,
                ],
            ], 200);
        }

        if ($status === 'expired') {
            $taskRecord->update([
                'model' => (string) data_get($response, 'data.model', $taskRecord->model ?? config('services.grok.video_model')),
                'status' => AiVideoGenerationTask::STATUS_EXPIRED,
                'error_message' => 'Срок ожидания генерации видео истёк',
            ]);
            $this->aiVideoGenerationService->touchGeneration($taskRecord->generation);

            return response()->json([
                'success' => false,
                'messages' => ['Срок ожидания генерации видео истёк. Запустите генерацию повторно.'],
                'data' => [
                    'request_id' => $requestId,
                    'status' => 'expired',
                    'generation_id' => $taskRecord->video_generation_id,
                ],
            ], 200);
        }

        if ($status !== 'done') {
            return response()->json([
                'success' => true,
                'messages' => ['Получен промежуточный статус'],
                'data' => [
                    'request_id' => $requestId,
                    'status' => $status,
                    'generation_id' => $taskRecord->video_generation_id,
                ],
            ], 200);
        }

        $video = data_get($response, 'data.video');
        if (! is_array($video) || trim((string) ($video['url'] ?? '')) === '') {
            $taskRecord->update([
                'model' => (string) data_get($response, 'data.model', $taskRecord->model ?? config('services.grok.video_model')),
                'status' => AiVideoGenerationTask::STATUS_FAILED,
                'error_message' => 'Grok API не вернул ссылку на видео',
            ]);
            $this->aiVideoGenerationService->touchGeneration($taskRecord->generation);

            return response()->json([
                'success' => false,
                'messages' => ['Grok API не вернул ссылку на видео'],
            ], 200);
        }

        if (! $this->consumeVideoLimitOnce($taskRecord, $subscription)) {
            return response()->json([
                'success' => false,
                'messages' => ['Не удалось списать лимит AI_VIDEO_QUERY'],
            ], 402);
        }

        $providerVideoUrl = (string) ($video['url'] ?? '');

        try {
            $storedVideoMeta = $this->aiMediaStorageService->storeVideoByUrlAndGetSignedUrl(
                $providerVideoUrl,
                $userId
            );
        } catch (Throwable $exception) {
            $taskRecord->update([
                'model' => (string) data_get($response, 'data.model', $taskRecord->model ?? config('services.grok.video_model')),
                'status' => AiVideoGenerationTask::STATUS_FAILED,
                'error_message' => 'Не удалось сохранить видео в хранилище: ' . $exception->getMessage(),
            ]);
            $this->aiVideoGenerationService->touchGeneration($taskRecord->generation);

            return response()->json([
                'success' => false,
                'messages' => ['Не удалось сохранить сгенерированное видео. Попробуйте позже.'],
            ], 200);
        }

        $storedPath = (string) ($storedVideoMeta['path'] ?? '');
        $panelVideoUrl = $this->aiMediaStorageService->resolvePanelMediaUrl(path: $storedPath)
            ?? (string) ($storedVideoMeta['signed_url'] ?? '');

        $resultVideo = [
            'url' => $panelVideoUrl,
            'url_preview' => $panelVideoUrl,
            'provider_url' => $providerVideoUrl,
            'path' => $storedPath,
            'duration' => (int) ($video['duration'] ?? 0),
        ];

        $taskRecord->update([
            'model' => (string) data_get($response, 'data.model', $taskRecord->model ?? config('services.grok.video_model')),
            'status' => AiVideoGenerationTask::STATUS_DONE,
            'result_video' => $resultVideo,
            'error_message' => null,
        ]);
        $this->aiVideoGenerationService->touchGeneration($taskRecord->generation);

        $limits = $this->getLimits($subscription->fresh());

        return response()->json([
            'success' => true,
            'messages' => ['Видео готово'],
            'data' => [
                'request_id' => $requestId,
                'status' => 'done',
                'video' => [
                    'url' => $panelVideoUrl,
                ],
                'model' => (string) data_get($response, 'data.model', config('services.grok.video_model')),
                'generation_id' => $taskRecord->video_generation_id,
            ],
            'meta' => [
                'limits' => $limits,
            ],
        ], 200);
    }

    private function hasEnoughLimit(SubscribersSubscriptions $subscription, string $limitKey, int $required): bool
    {
        if ($required <= 0) {
            return true;
        }

        $current = (int) ($subscription->getMonthLimit($limitKey) ?: 0);

        return $current >= $required;
    }

    private function consumeVideoLimitOnce(AiVideoGenerationTask $taskRecord, SubscribersSubscriptions $subscription): bool
    {
        return DB::transaction(function () use ($taskRecord, $subscription) {
            /** @var AiVideoGenerationTask|null $lockedTask */
            $lockedTask = AiVideoGenerationTask::lockForUpdate()->find($taskRecord->id);
            if (! $lockedTask) {
                return false;
            }

            if ($lockedTask->limit_consumed_at !== null) {
                return true;
            }

            $freshSubscription = SubscribersSubscriptions::lockForUpdate()->find($subscription->id);
            if (! $freshSubscription) {
                return false;
            }

            $requiredVideoLimit = $this->resolveVideoLimitCost($lockedTask->duration, $lockedTask->resolution);

            $available = (int) ($freshSubscription->getMonthLimit('ai_video_query') ?: 0);
            if ($available < $requiredVideoLimit) {
                return false;
            }

            for ($i = 0; $i < $requiredVideoLimit; $i++) {
                if (! $freshSubscription->minusMonthLimit('ai_video_query')) {
                    return false;
                }
            }

            $lockedTask->update([
                'limit_consumed_at' => now(),
            ]);

            return true;
        });
    }

    private function getLimits(SubscribersSubscriptions $subscription): array
    {
        $textBase = $this->getRawMonthLimitValue($subscription, 'ai_text_query');
        $textExtra = $this->getRawExtraMonthLimitValue($subscription, 'ai_text_query');
        $imageBase = $this->getRawMonthLimitValue($subscription, 'ai_image_query');
        $imageExtra = $this->getRawExtraMonthLimitValue($subscription, 'ai_image_query');
        $videoBase = $this->getRawMonthLimitValue($subscription, 'ai_video_query');
        $videoExtra = $this->getRawExtraMonthLimitValue($subscription, 'ai_video_query');

        return [
            'AI_TEXT_QUERY' => $textBase,
            'AI_IMAGE_QUERY' => $imageBase,
            'AI_VIDEO_QUERY' => $videoBase,
            'AI_TEXT_QUERY_EXTRA' => $textExtra,
            'AI_IMAGE_QUERY_EXTRA' => $imageExtra,
            'AI_VIDEO_QUERY_EXTRA' => $videoExtra,
            'AI_TEXT_QUERY_TOTAL' => $textBase + $textExtra,
            'AI_IMAGE_QUERY_TOTAL' => $imageBase + $imageExtra,
            'AI_VIDEO_QUERY_TOTAL' => $videoBase + $videoExtra,
        ];
    }

    private function getRawMonthLimitValue(SubscribersSubscriptions $subscription, string $limitKey): int
    {
        $limits = $subscription->limits_month;

        if (! is_array($limits)) {
            return 0;
        }

        return max(0, (int) ($limits[$limitKey] ?? 0));
    }

    private function getRawExtraMonthLimitValue(SubscribersSubscriptions $subscription, string $limitKey): int
    {
        $extraLimits = $subscription->extra_limits_month;

        if (! is_array($extraLimits)) {
            return 0;
        }

        return max(0, (int) ($extraLimits[$limitKey] ?? 0));
    }

    private function resolveResolution(string $resolution): string
    {
        $normalized = mb_strtolower(trim($resolution));

        return match ($normalized) {
            '', 'default', '480', '480p' => '480p',
            '720', '720p' => '720p',
            default => $normalized,
        };
    }

    private function getRequestedAspectRatio(Request $request): string
    {
        return (string) ($request->input('aspect_ratio') ?? $request->input('aspectRatio', '16:9'));
    }

    private function resolveAspectRatio(string $aspectRatio): string
    {
        $normalized = trim($aspectRatio);

        return $normalized !== '' ? $normalized : '16:9';
    }

    private function resolveVideoLimitCost(?int $duration, string $resolution): int
    {
        $seconds = max(1, (int) ($duration ?? 1));
        $qualityMultiplier = $resolution === '720p' ? 2 : 1;

        return $seconds * $qualityMultiplier;
    }

    private function resolvePublicErrorMessage(?string $providerMessage, ?int $statusCode = null): string
    {
        if ($this->isModerationError($providerMessage)) {
            return self::MODERATION_PUBLIC_ERROR_MESSAGE;
        }

        if ($this->isHighDemandError($providerMessage, $statusCode)) {
            return self::HIGH_DEMAND_PUBLIC_ERROR_MESSAGE;
        }

        return self::PUBLIC_ERROR_MESSAGE;
    }

    private function isHighDemandError(?string $providerMessage, ?int $statusCode = null): bool
    {
        if ((int) $statusCode === 429) {
            return true;
        }

        $message = mb_strtolower((string) $providerMessage);

        return str_contains($message, 'high demand')
            || str_contains($message, 'try again later')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'resource exhausted')
            || str_contains($message, 'rate limit');
    }

    private function isModerationError(?string $providerMessage): bool
    {
        $message = mb_strtolower((string) $providerMessage);

        return str_contains($message, 'moderation')
            || str_contains($message, 'filtered by moderation')
            || str_contains($message, 'content policy')
            || str_contains($message, 'safety');
    }

    private function validateInputImageSize(string $imageInput): ?string
    {
        $trimmed = trim($imageInput);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return null;
        }

        $base64Part = $trimmed;
        if (str_starts_with($trimmed, 'data:')) {
            if (! preg_match('/^data:[^;]+;base64,(.*)$/s', $trimmed, $matches)) {
                return null;
            }

            $base64Part = (string) ($matches[1] ?? '');
        }

        $normalizedBase64 = preg_replace('/\s+/', '', $base64Part) ?? '';
        if ($normalizedBase64 === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $normalizedBase64) !== 1) {
            return null;
        }

        $decodedSize = $this->estimateDecodedBase64Bytes($normalizedBase64);
        if ($decodedSize > self::MAX_INPUT_IMAGE_BYTES) {
            return 'Размер изображения не должен превышать 10MB';
        }

        return null;
    }

    private function validateInputImageFormat(string $imageInput): ?string
    {
        $trimmed = trim($imageInput);
        if ($trimmed === '') {
            return 'Изображение не передано';
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return null;
        }

        if (str_starts_with($trimmed, 'data:')) {
            return preg_match('/^data:[^;]+;base64,(.*)$/s', $trimmed) === 1
                ? null
                : 'Некорректный формат data URI изображения';
        }

        return preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $trimmed) === 1
            ? null
            : 'Неподдерживаемый формат изображения';
    }

    /**
     * @return array<int, string>
     */
    private function normalizeImagesInput(mixed $images): array
    {
        if (! is_array($images)) {
            return [];
        }

        $normalized = [];
        foreach ($images as $imageInput) {
            $image = trim((string) $imageInput);
            if ($image !== '') {
                $normalized[] = $image;
            }
        }

        return $normalized;
    }

    private function estimateDecodedBase64Bytes(string $base64): int
    {
        $length = strlen($base64);
        if ($length === 0) {
            return 0;
        }

        $padding = 0;
        if (str_ends_with($base64, '==')) {
            $padding = 2;
        } elseif (str_ends_with($base64, '=')) {
            $padding = 1;
        }

        return (int) floor(($length * 3) / 4) - $padding;
    }
}
