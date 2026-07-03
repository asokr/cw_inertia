<?php

namespace App\Http\Controllers\Api\Subscriber\Ai;

use Throwable;
use RuntimeException;
use App\Enums\AiTaskType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\AiRequestLog;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Services\Grok\GrokVideoApiClient;
use App\Services\Ai\AiMediaStorageService;
use App\Models\Subscribers\SubscribersSubscriptions;

class GrokVideoController extends Controller
{
    private const PUBLIC_ERROR_MESSAGE = 'Ошибка при работе. Попробуйте позже.';
    private const HIGH_DEMAND_PUBLIC_ERROR_MESSAGE = 'ИИ временно перегружен, попробуйте позже.';
    private const MODERATION_PUBLIC_ERROR_MESSAGE = 'Видео не прошло модерацию. Измените запрос и попробуйте снова. При ограничении по контенту лимиты списываются, будьте аккуратнее.';
    private const MAX_INPUT_IMAGE_BYTES = 10485760;

    public function __construct(
        private readonly GrokVideoApiClient $grokVideoApiClient,
        private readonly AiMediaStorageService $aiMediaStorageService
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

        $requestPayloadForLog = array_merge($request->all(), [
            'resolution' => $resolution,
        ]);

        if (is_string($aspectRatio) && $aspectRatio !== '') {
            $requestPayloadForLog['aspect_ratio'] = $aspectRatio;
        } else {
            unset($requestPayloadForLog['aspect_ratio'], $requestPayloadForLog['aspectRatio']);
        }

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

                $logRecord = AiRequestLog::create([
                    'user_id' => $user?->id,
                    'subscriber_id' => $subscriberId,
                    'task_type' => $taskType,
                    'provider' => 'grok',
                    'model' => (string) data_get($response, 'data.model', config('services.grok.video_model')),
                    'request_payload' => $this->buildDbRequestPayload($requestPayloadForLog, $response, $sourceImageMeta),
                    'provider_response_payload' => $this->extractProviderResponsePayload($response),
                    'response_type' => 'video',
                    'generation_status' => $isModerationError ? 'filtered_by_moderation' : 'failed',
                    'images_count' => 0,
                    'videos_count' => 0,
                    'status_code' => $statusCode,
                    'error_message' => $message,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($isModerationError) {
                    $this->consumeVideoLimitOnce($logRecord, $subscription);

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

            AiRequestLog::create([
                'user_id' => $user?->id,
                'subscriber_id' => $subscriberId,
                'task_type' => $taskType,
                'provider' => 'grok',
                'model' => (string) data_get($response, 'data.model', config('services.grok.video_model')),
                'external_request_id' => $requestId,
                'request_payload' => $this->buildDbRequestPayload($requestPayloadForLog, $response, $sourceImageMeta),
                'provider_response_payload' => $this->extractProviderResponsePayload($response),
                'response_type' => 'video',
                'generation_status' => 'pending',
                'images_count' => 0,
                'videos_count' => 0,
                'status_code' => 202,
                'error_message' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'messages' => ['Задача генерации видео создана'],
                'data' => [
                    'request_id' => $requestId,
                    'status' => 'pending',
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

        $taskType = AiTaskType::GENERATE_VIDEO_FROM_IMAGE->value;
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

        $requestPayloadForLog = array_merge($request->all(), [
            'task_type' => $taskType,
            'resolution' => $resolution,
            'images' => $referenceImagesForProvider,
        ]);

        $options = [
            'duration' => $request->input('duration'),
            'resolution' => $resolution,
            'reference_images' => $referenceImagesForProvider,
        ];

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

                $logRecord = AiRequestLog::create([
                    'user_id' => $user?->id,
                    'subscriber_id' => $subscriberId,
                    'task_type' => $taskType,
                    'provider' => 'grok',
                    'model' => (string) data_get($response, 'data.model', config('services.grok.video_model')),
                    'request_payload' => $this->buildDbRequestPayload($requestPayloadForLog, $response, null, $sourceImagesMeta),
                    'provider_response_payload' => $this->extractProviderResponsePayload($response),
                    'response_type' => 'video',
                    'generation_status' => $isModerationError ? 'filtered_by_moderation' : 'failed',
                    'images_count' => count($referenceImagesForProvider),
                    'videos_count' => 0,
                    'status_code' => $statusCode,
                    'error_message' => $message,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($isModerationError) {
                    $this->consumeVideoLimitOnce($logRecord, $subscription);

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

            AiRequestLog::create([
                'user_id' => $user?->id,
                'subscriber_id' => $subscriberId,
                'task_type' => $taskType,
                'provider' => 'grok',
                'model' => (string) data_get($response, 'data.model', config('services.grok.video_model')),
                'external_request_id' => $requestId,
                'request_payload' => $this->buildDbRequestPayload($requestPayloadForLog, $response, null, $sourceImagesMeta),
                'provider_response_payload' => $this->extractProviderResponsePayload($response),
                'response_type' => 'video',
                'generation_status' => 'pending',
                'images_count' => count($referenceImagesForProvider),
                'videos_count' => 0,
                'status_code' => 202,
                'error_message' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'messages' => ['Задача генерации видео создана'],
                'data' => [
                    'request_id' => $requestId,
                    'status' => 'pending',
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

        $logRecord = AiRequestLog::query()
            ->where('provider', 'grok')
            ->where('external_request_id', $requestId)
            ->where('subscriber_id', $subscriberId)
            ->latest('id')
            ->first();

        if (! $logRecord) {
            return response()->json([
                'success' => false,
                'messages' => ['Задача генерации видео не найдена'],
            ], 404);
        }

        $response = $this->grokVideoApiClient->getGeneration($requestId);

        if (! ($response['success'] ?? false)) {
            $message = (string) (($response['messages'][0] ?? null) ?: 'Ошибка Grok API');
            $statusCode = (int) ($response['status'] ?? 503);

            $isModerationError = $this->isModerationError($message);
            $generationStatus = $isModerationError ? 'filtered_by_moderation' : 'failed';

            $logRecord->update([
                'model' => (string) data_get($response, 'data.model', $logRecord->model ?? config('services.grok.video_model')),
                'generation_status' => $generationStatus,
                'status_code' => $statusCode,
                'error_message' => $message,
                'provider_response_payload' => $this->extractProviderResponsePayload($response),
                'updated_at' => now(),
            ]);

            if ($isModerationError) {
                $this->consumeVideoLimitOnce($logRecord, $subscription);

                return response()->json([
                    'success' => false,
                    'messages' => [$this->resolvePublicErrorMessage($message, $statusCode)],
                    'data' => [
                        'request_id' => $requestId,
                        'status' => 'filtered_by_moderation',
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
            $logRecord->update([
                'model' => (string) data_get($response, 'data.model', $logRecord->model ?? config('services.grok.video_model')),
                'generation_status' => 'pending',
                'status_code' => 202,
                'error_message' => null,
                'provider_response_payload' => $this->extractProviderResponsePayload($response),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'messages' => ['Видео ещё генерируется'],
                'data' => [
                    'request_id' => $requestId,
                    'status' => 'pending',
                ],
            ], 200);
        }

        if ($status === 'expired') {
            $logRecord->update([
                'model' => (string) data_get($response, 'data.model', $logRecord->model ?? config('services.grok.video_model')),
                'generation_status' => 'expired',
                'status_code' => 410,
                'error_message' => 'Срок ожидания генерации видео истёк',
                'provider_response_payload' => $this->extractProviderResponsePayload($response),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => false,
                'messages' => ['Срок ожидания генерации видео истёк. Запустите генерацию повторно.'],
                'data' => [
                    'request_id' => $requestId,
                    'status' => 'expired',
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
                ],
            ], 200);
        }

        $video = data_get($response, 'data.video');
        if (! is_array($video) || trim((string) ($video['url'] ?? '')) === '') {
            $logRecord->update([
                'model' => (string) data_get($response, 'data.model', $logRecord->model ?? config('services.grok.video_model')),
                'generation_status' => 'failed',
                'status_code' => 500,
                'error_message' => 'Grok API не вернул ссылку на видео',
                'provider_response_payload' => $this->extractProviderResponsePayload($response),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => false,
                'messages' => ['Grok API не вернул ссылку на видео'],
            ], 200);
        }

        if (! $this->consumeVideoLimitOnce($logRecord, $subscription)) {
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
            $logRecord->update([
                'model' => (string) data_get($response, 'data.model', $logRecord->model ?? config('services.grok.video_model')),
                'generation_status' => 'failed',
                'status_code' => 500,
                'error_message' => 'Не удалось сохранить видео в хранилище: ' . $exception->getMessage(),
                'provider_response_payload' => $this->extractProviderResponsePayload($response),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => false,
                'messages' => ['Не удалось сохранить сгенерированное видео. Попробуйте позже.'],
            ], 200);
        }

        $responseVideos = [[
            'url' => (string) ($storedVideoMeta['signed_url'] ?? ''),
            'url_preview' => (string) ($storedVideoMeta['url_preview'] ?? ''),
            'provider_url' => $providerVideoUrl,
            'path' => (string) ($storedVideoMeta['path'] ?? ''),
            'duration' => (int) ($video['duration'] ?? 0),
        ]];

        $logRecord->update([
            'model' => (string) data_get($response, 'data.model', $logRecord->model ?? config('services.grok.video_model')),
            'generation_status' => 'done',
            'response_type' => 'video',
            'response_videos' => $responseVideos,
            'videos_count' => 1,
            'status_code' => 200,
            'error_message' => null,
            'provider_response_payload' => $this->extractProviderResponsePayload($response),
            'updated_at' => now(),
        ]);

        $limits = $this->getLimits($subscription->fresh());

        return response()->json([
            'success' => true,
            'messages' => ['Видео готово'],
            'data' => [
                'request_id' => $requestId,
                'status' => 'done',
                'video' => [
                    'url' => (string) ($storedVideoMeta['signed_url'] ?? ''),
                ],
                'model' => (string) data_get($response, 'data.model', config('services.grok.video_model')),
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

    private function consumeVideoLimitOnce(AiRequestLog $logRecord, SubscribersSubscriptions $subscription): bool
    {
        return DB::transaction(function () use ($logRecord, $subscription) {
            /** @var AiRequestLog|null $lockedLog */
            $lockedLog = AiRequestLog::lockForUpdate()->find($logRecord->id);
            if (! $lockedLog) {
                return false;
            }

            if ($lockedLog->limit_consumed_at !== null) {
                return true;
            }

            $freshSubscription = SubscribersSubscriptions::lockForUpdate()->find($subscription->id);
            if (! $freshSubscription) {
                return false;
            }

            $requiredVideoLimit = $this->extractVideoLimitCostFromRequestPayload($lockedLog->request_payload);

            $available = (int) ($freshSubscription->getMonthLimit('ai_video_query') ?: 0);
            if ($available < $requiredVideoLimit) {
                return false;
            }

            for ($i = 0; $i < $requiredVideoLimit; $i++) {
                if (! $freshSubscription->minusMonthLimit('ai_video_query')) {
                    return false;
                }
            }

            $lockedLog->update([
                'limit_consumed_at' => now(),
                'updated_at' => now(),
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

    private function extractVideoLimitCostFromRequestPayload(mixed $requestPayload): int
    {
        if (! is_array($requestPayload)) {
            return 1;
        }

        $duration = (int) data_get($requestPayload, 'duration', 1);
        $resolution = $this->resolveResolution((string) data_get($requestPayload, 'resolution', '480p'));

        return $this->resolveVideoLimitCost($duration, $resolution);
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

    private function sanitizePayload(array $payload): array
    {
        if (isset($payload['image']) && is_string($payload['image']) && $payload['image'] !== '') {
            $payload['image'] = 'image_input_length:' . mb_strlen($payload['image']);
        }

        if (isset($payload['images']) && is_array($payload['images'])) {
            $payload['images'] = array_map(function ($image) {
                if (! is_string($image) || $image === '') {
                    return $image;
                }

                return 'image_input_length:' . mb_strlen($image);
            }, $payload['images']);
        }

        return $payload;
    }

    private function buildDbRequestPayload(array $requestPayload, array $response, ?array $sourceImageMeta = null, array $sourceImagesMeta = []): array
    {
        $payload = $this->sanitizePayload($requestPayload);
        $providerOutboundPayload = data_get($response, 'request_payload');

        if (is_array($providerOutboundPayload)) {
            $payload['_grok_request_payload'] = $providerOutboundPayload;
        }

        if (is_array($sourceImageMeta)) {
            $payload['_source_image'] = [
                'path' => (string) ($sourceImageMeta['path'] ?? ''),
                'url_preview' => (string) ($sourceImageMeta['url_preview'] ?? ''),
                'mime_type' => (string) ($sourceImageMeta['mime_type'] ?? ''),
                'size' => (int) ($sourceImageMeta['size'] ?? 0),
            ];
        }

        if ($sourceImagesMeta !== []) {
            $payload['_source_images'] = array_map(function (array $meta): array {
                return [
                    'path' => (string) ($meta['path'] ?? ''),
                    'url_preview' => (string) ($meta['url_preview'] ?? ''),
                    'mime_type' => (string) ($meta['mime_type'] ?? ''),
                    'size' => (int) ($meta['size'] ?? 0),
                ];
            }, $sourceImagesMeta);
        }

        return $payload;
    }

    private function extractProviderResponsePayload(array $response): ?array
    {
        $providerResponsePayload = data_get($response, 'response_payload');

        if (is_array($providerResponsePayload)) {
            return $providerResponsePayload;
        }

        $data = data_get($response, 'data');

        return is_array($data) ? $data : null;
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
