<?php

namespace App\Services\Ai;

use Throwable;
use App\Enums\AiTaskType;
use App\Models\AiImageGenerationTask;
use App\Models\AiRequestLog;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Support\ToolLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\Gemini\GeminiApiClient;
use App\Services\Grok\GrokImageApiClient;

class AiImageService
{
    private const PUBLIC_ERROR_MESSAGE = 'Ошибка при работе. Попробуйте позже.';
    private const HIGH_DEMAND_PUBLIC_ERROR_MESSAGE = 'ИИ сейчас занят большим объёмом задач. Попробуйте чуть позже — всё обязательно будет работать.';
    private const MODERATION_PUBLIC_ERROR_MESSAGE = 'Запрос отклонён модерацией. Попробуйте изменить описание.';
    private const MAX_INPUT_IMAGE_BYTES = 10485760;

    public function __construct(
        private readonly GeminiApiClient $geminiApiClient,
        private readonly AiMediaStorageService $aiMediaStorageService,
        private readonly GrokImageApiClient $grokImageApiClient,
        private readonly AiImageGenerationService $aiImageGenerationService,
    ) {}

    public function start(Request $request, SubscribersSubscriptions $subscription, int $userId, int $subscriberId): JsonResponse
    {
        $taskType = (string) $request->input('task_type');
        $provider = 'gemini';
        $variants = 1;
        $rawResolution = trim((string) $request->input('resolution', 'default'));
        $resolution = $this->resolveResolution($rawResolution);
        $resolutionLimitCost = $this->resolveImageLimitCost($resolution);
        $geminiImageSize = $this->resolveGeminiImageSize($resolution);
        $aspectRatio = $this->resolveAspectRatio($request->input('aspectRatio'));
        $prompt = $this->resolveImagePrompt($request);

        $generation = $this->aiImageGenerationService->resolveForStart(
            $request->filled('generation_uuid') ? (string) $request->input('generation_uuid') : null,
            $subscriberId,
            $userId,
            $prompt,
        );

        $sourceImagesMeta = $this->storeSourceImages($request, $taskType, $userId);

        $imageOptions = [
            'responseModalities' => ['IMAGE', 'TEXT'],
            'imageSize' => $geminiImageSize,
        ];

        if ($aspectRatio !== null) {
            $imageOptions['aspectRatio'] = $aspectRatio;
        }

        $options = $this->geminiApiClient->buildImageOptions($imageOptions);

        if ($taskType === AiTaskType::EDIT_IMAGE->value) {
            $response = $this->geminiApiClient->editImage(
                image: (string) $request->input('image', ''),
                prompt: $prompt,
                options: $options
            );
        } else {
            $inputImages = $this->resolveInputImages($request);
            $response = empty($inputImages)
                ? $this->geminiApiClient->generateImage($prompt, $options)
                : $this->geminiApiClient->generateImageWithImages($prompt, $inputImages, $options);
        }

        $images = [];
        if ($response['success'] ?? false) {
            $images = $this->geminiApiClient->extractImages((array) ($response['data'] ?? []));
        }

        if (count($images) < $variants) {
            $missing = $variants - count($images);

            for ($i = 0; $i < $missing; $i++) {
                if ($taskType === AiTaskType::EDIT_IMAGE->value) {
                    $singleResponse = $this->geminiApiClient->editImage(
                        image: (string) $request->input('image', ''),
                        prompt: $prompt,
                        options: $options
                    );
                } else {
                    $inputImages = $this->resolveInputImages($request);
                    $singleResponse = empty($inputImages)
                        ? $this->geminiApiClient->generateImage($prompt, $options)
                        : $this->geminiApiClient->generateImageWithImages($prompt, $inputImages, $options);
                }

                if (! ($singleResponse['success'] ?? false)) {
                    Log::warning('Gemini image fallback request failed', [
                        'task_type' => $taskType,
                        'aspect_ratio' => $aspectRatio,
                        'image_size' => $geminiImageSize,
                        'status' => $singleResponse['status'] ?? null,
                        'messages' => $singleResponse['messages'] ?? [],
                    ]);
                    continue;
                }

                $singleImages = $this->geminiApiClient->extractImages((array) ($singleResponse['data'] ?? []));
                if (! empty($singleImages)) {
                    $images[] = $singleImages[0];
                }
            }
        }

        if (count($images) < $variants) {
            $missing = $variants - count($images);
            $geminiImagesCount = count($images);
            $fallbackImages = $this->generateImagesByGrokFallback($request, $taskType, $prompt, $aspectRatio, $missing);

            if ($fallbackImages !== []) {
                if ($geminiImagesCount === 0) {
                    $provider = 'grok';
                    $response['provider'] = 'grok';
                    $response['model'] = (string) config('services.grok.image_model', 'grok-imagine-image-quality');
                }

                $images = array_merge($images, $fallbackImages);
            }
        }

        if (empty($images)) {
            $message = $this->resolveImageFailureMessage($response);
            $statusCode = (int) ($response['status'] ?? 503);
            $isModerationError = $this->isModerationFailure($response, $message);
            $publicMessage = $this->resolvePublicErrorMessage($message, $statusCode, $isModerationError);

            $this->logRequest(
                userId: $userId,
                subscriberId: $subscriberId,
                taskType: $taskType,
                requestPayload: $this->buildDbRequestPayload($request->all(), $response),
                responseType: 'image',
                imagesCount: 0,
                usage: data_get($response, 'data.usageMetadata'),
                model: $this->resolveModelFromResponse($response),
                statusCode: $statusCode,
                errorMessage: $message,
                provider: $provider,
            );

            $this->aiImageGenerationService->createTask(
                generation: $generation,
                subscriberId: $subscriberId,
                userId: $userId,
                taskType: $taskType,
                prompt: $prompt,
                imageVariants: $variants,
                resolution: $resolution,
                aspectRatio: $aspectRatio,
                sourceImages: $sourceImagesMeta !== [] ? $sourceImagesMeta : null,
                status: AiImageGenerationTask::STATUS_FAILED,
                model: $this->resolveModelFromResponse($response),
                errorMessage: $message,
            );

            return response()->json([
                'success' => false,
                'messages' => [$publicMessage],
                'data' => [
                    'generation_id' => $generation->id,
                    'generation_uuid' => $generation->uuid,
                ],
                'meta' => [
                    'moderation' => $isModerationError,
                ],
            ], 200);
        }

        $generatedCount = min($variants, count($images));
        $imagesForResponse = array_slice($images, 0, $generatedCount);
        $storedImages = $this->prepareResponseImagesForLog($imagesForResponse, $userId);

        if ($storedImages === []) {
            $this->aiImageGenerationService->createTask(
                generation: $generation,
                subscriberId: $subscriberId,
                userId: $userId,
                taskType: $taskType,
                prompt: $prompt,
                imageVariants: $variants,
                resolution: $resolution,
                aspectRatio: $aspectRatio,
                sourceImages: $sourceImagesMeta !== [] ? $sourceImagesMeta : null,
                status: AiImageGenerationTask::STATUS_FAILED,
                errorMessage: 'Не удалось сохранить изображения в хранилище',
            );

            return response()->json([
                'success' => false,
                'messages' => ['Не удалось сохранить изображения в хранилище'],
                'data' => [
                    'generation_id' => $generation->id,
                    'generation_uuid' => $generation->uuid,
                ],
            ], 200);
        }

        $generatedCount = count($storedImages);
        $consumptionCount = $generatedCount * $resolutionLimitCost;

        if (! $this->consumeLimit($subscription, 'ai_image_query', $consumptionCount)) {
            return response()->json([
                'success' => false,
                'messages' => ['Не удалось списать лимит AI_IMAGE_QUERY'],
            ], 200);
        }

        $limits = $this->getLimits($subscription->fresh());

        $this->logRequest(
            userId: $userId,
            subscriberId: $subscriberId,
            taskType: $taskType,
            requestPayload: $this->buildDbRequestPayload($request->all(), $response),
            responseType: 'image',
            imagesCount: $generatedCount,
            usage: data_get($response, 'data.usageMetadata'),
            model: $this->resolveModelFromResponse($response),
            statusCode: 200,
            errorMessage: null,
            responseImages: $storedImages,
            provider: $provider,
        );

        $task = $this->aiImageGenerationService->createTask(
            generation: $generation,
            subscriberId: $subscriberId,
            userId: $userId,
            taskType: $taskType,
            prompt: $prompt,
            imageVariants: $variants,
            resolution: $resolution,
            aspectRatio: $aspectRatio,
            sourceImages: $sourceImagesMeta !== [] ? $sourceImagesMeta : null,
            status: AiImageGenerationTask::STATUS_DONE,
            resultImages: $storedImages,
            model: $this->resolveModelFromResponse($response),
        );

        $mappedTask = $this->aiImageGenerationService->mapTaskForFrontend($task);

        $imageUrls = array_values(array_filter(array_map(
            fn (array $item): ?string => $this->aiMediaStorageService->resolvePanelMediaUrl(
                url: (string) ($item['url'] ?? $item['url_preview'] ?? $item['signed_url'] ?? ''),
                path: (string) ($item['path'] ?? ''),
            ),
            $storedImages,
        )));

        return response()->json([
            'success' => true,
            'type' => 'image',
            'images' => $imageUrls,
            'limits' => $limits,
            'data' => [
                'generation_id' => $generation->id,
                'generation_uuid' => $generation->uuid,
                'task' => $mappedTask,
            ],
        ], 200);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storeSourceImages(Request $request, string $taskType, int $userId): array
    {
        $stored = [];

        if ($userId <= 0) {
            return $stored;
        }

        $inputs = [];

        if ($taskType === AiTaskType::EDIT_IMAGE->value) {
            $primary = trim((string) $request->input('image', ''));
            if ($primary !== '') {
                $inputs[] = $primary;
            }
        }

        $inputs = array_values(array_unique(array_merge($inputs, $this->resolveInputImages($request))));

        foreach ($inputs as $imageInput) {
            try {
                $meta = $this->aiMediaStorageService->storeImageAndGetSignedUrl($imageInput, $userId);
                $stored[] = [
                    'mime_type' => (string) ($meta['mime_type'] ?? 'image/png'),
                    'path' => (string) ($meta['path'] ?? ''),
                    'url' => (string) ($meta['signed_url'] ?? $meta['url_preview'] ?? ''),
                    'url_preview' => (string) ($meta['url_preview'] ?? ''),
                    'size' => (int) ($meta['size'] ?? 0),
                ];
            } catch (Throwable $exception) {
                Log::warning('AI image source storage failed', [
                    'user_id' => $userId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return array_values(array_filter($stored, static fn (array $item): bool => ($item['path'] ?? '') !== ''));
    }

    private function resolveImagePrompt(Request $request): string
    {
        $explicitPrompt = trim((string) $request->input('image_prompt', ''));

        return $explicitPrompt;
    }

    private function resolveImageFailureMessage(array $response): string
    {
        $message = trim((string) data_get($response, 'messages.0', ''));
        if ($message !== '') {
            return $message;
        }

        $blockReason = trim((string) data_get($response, 'data.promptFeedback.blockReason', ''));
        $finishReason = trim((string) data_get($response, 'data.candidates.0.finishReason', ''));

        $details = [];

        if ($blockReason !== '') {
            $details[] = 'prompt_block_reason=' . $blockReason;
        }

        if ($finishReason !== '') {
            $details[] = 'finish_reason=' . $finishReason;
        }

        if (! empty($details)) {
            return 'Gemini не вернул изображения: ' . implode('; ', $details);
        }

        return 'Gemini не вернул изображения';
    }

    private function hasEnoughLimit(SubscribersSubscriptions $subscription, string $limitKey, int $required): bool
    {
        if ($required <= 0) {
            return true;
        }

        $current = (int) ($subscription->getMonthLimit($limitKey) ?: 0);

        return $current >= $required;
    }

    public function requiredImageLimit(Request $request): int
    {
        $resolution = $this->resolveResolution((string) $request->input('resolution', 'default'));

        return $this->resolveImageLimitCost($resolution);
    }

    public function hasEnoughImageLimit(SubscribersSubscriptions $subscription, Request $request): bool
    {
        return $this->hasEnoughLimit($subscription, 'ai_image_query', $this->requiredImageLimit($request));
    }

    private function consumeLimit(SubscribersSubscriptions $subscription, string $limitKey, int $count): bool
    {
        if ($count <= 0) {
            return true;
        }

        return DB::transaction(function () use ($subscription, $limitKey, $count) {
            $freshSubscription = SubscribersSubscriptions::lockForUpdate()->find($subscription->id);
            if (! $freshSubscription) {
                return false;
            }

            $available = (int) ($freshSubscription->getMonthLimit($limitKey) ?: 0);
            if ($available < $count) {
                return false;
            }

            for ($i = 0; $i < $count; $i++) {
                if (! $freshSubscription->minusMonthLimit($limitKey)) {
                    return false;
                }

                $freshSubscription->refresh();
            }

            return true;
        });
    }

    /**
     * @return array<string, int>
     */
    private function getLimits(SubscribersSubscriptions $subscription): array
    {
        if (ToolLimits::bypassesFor(auth()->user())) {
            return ToolLimits::unlimitedAiLimits();
        }

        $textBase = $this->getRawMonthLimitValue($subscription, 'ai_text_query');
        $textExtra = $this->getRawExtraMonthLimitValue($subscription, 'ai_text_query');
        $imageBase = $this->getRawMonthLimitValue($subscription, 'ai_image_query');
        $imageExtra = $this->getRawExtraMonthLimitValue($subscription, 'ai_image_query');

        return [
            'AI_TEXT_QUERY' => $textBase,
            'AI_IMAGE_QUERY' => $imageBase,
            'AI_TEXT_QUERY_EXTRA' => $textExtra,
            'AI_IMAGE_QUERY_EXTRA' => $imageExtra,
            'AI_TEXT_QUERY_TOTAL' => $textBase + $textExtra,
            'AI_IMAGE_QUERY_TOTAL' => $imageBase + $imageExtra,
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
            '', 'default', 'standart', 'standard' => 'default',
            '1k' => '1k',
            '2k' => '2k',
            '4k' => '4k',
            default => $normalized,
        };
    }

    private function resolveImageLimitCost(string $resolution): int
    {
        return match ($resolution) {
            '1k' => 2,
            '2k', '4k' => 3,
            default => 1,
        };
    }

    private function resolveGeminiImageSize(string $resolution): string
    {
        return match ($resolution) {
            '1k' => '1K',
            '2k' => '2K',
            '4k' => '4K',
            default => 'default',
        };
    }

    private function resolveAspectRatio(mixed $aspectRatio): ?string
    {
        if ($aspectRatio === null) {
            return null;
        }

        $normalized = trim((string) $aspectRatio);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array<int, string>
     */
    private function resolveInputImages(Request $request): array
    {
        $images = [];

        foreach ((array) $request->input('images', []) as $image) {
            if (! is_string($image)) {
                continue;
            }

            $normalized = trim($image);
            if ($normalized !== '') {
                $images[] = $normalized;
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateImagesByGrokFallback(Request $request, string $taskType, string $prompt, ?string $aspectRatio, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $inputImages = [];

        if ($taskType === AiTaskType::EDIT_IMAGE->value) {
            $primaryImage = trim((string) $request->input('image', ''));
            if ($primaryImage !== '') {
                $inputImages[] = $primaryImage;
            }
        }

        $inputImages = array_values(array_unique(array_merge($inputImages, $this->resolveInputImages($request))));

        $result = [];

        $fallbackOptions = [];

        if ($aspectRatio !== null) {
            $fallbackOptions['aspect_ratio'] = $aspectRatio;
        }

        for ($i = 0; $i < $count; $i++) {
            $fallbackResponse = $this->grokImageApiClient->generateOrEditImage(
                prompt: $prompt,
                images: $inputImages,
                options: $fallbackOptions,
            );

            if (! ($fallbackResponse['success'] ?? false)) {
                continue;
            }

            $extracted = $this->grokImageApiClient->extractImages((array) ($fallbackResponse['data'] ?? []));

            if ($extracted !== []) {
                $result[] = $extracted[0];
            }
        }

        return $result;
    }

    private function resolvePublicErrorMessage(?string $providerMessage, ?int $statusCode = null, bool $isModerationError = false): string
    {
        if ($isModerationError || $this->isModerationError($providerMessage)) {
            return self::MODERATION_PUBLIC_ERROR_MESSAGE;
        }

        if ($this->isHighDemandError($providerMessage, $statusCode)) {
            return self::HIGH_DEMAND_PUBLIC_ERROR_MESSAGE;
        }

        return self::PUBLIC_ERROR_MESSAGE;
    }

    private function isModerationFailure(array $response, string $message): bool
    {
        if ($this->isModerationError($message)) {
            return true;
        }

        $blockReason = trim((string) data_get($response, 'data.promptFeedback.blockReason', ''));
        if ($blockReason !== '') {
            return true;
        }

        $blockedSafetyCategories = collect((array) data_get($response, 'data.candidates.0.safetyRatings', []))
            ->filter(static fn (array $rating) => (bool) ($rating['blocked'] ?? false))
            ->count();

        return $blockedSafetyCategories > 0;
    }

    private function isModerationError(?string $providerMessage): bool
    {
        $message = mb_strtolower((string) $providerMessage);

        return str_contains($message, 'moderation')
            || str_contains($message, 'filtered by moderation')
            || str_contains($message, 'content policy')
            || str_contains($message, 'prompt_block_reason')
            || str_contains($message, 'safety')
            || str_contains($message, 'blockreason');
    }

    private function isHighDemandError(?string $providerMessage, ?int $statusCode = null): bool
    {
        if ((int) $statusCode === 429) {
            return true;
        }

        $message = mb_strtolower((string) $providerMessage);

        return str_contains($message, 'high demand')
            || str_contains($message, 'spikes in demand')
            || str_contains($message, 'try again later')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'resource exhausted')
            || str_contains($message, 'rate limit');
    }

    private function logRequest(
        int $userId,
        int $subscriberId,
        string $taskType,
        array $requestPayload,
        ?string $responseType,
        int $imagesCount,
        mixed $usage,
        ?string $model,
        int $statusCode,
        ?string $errorMessage,
        ?array $responseImages = null,
        string $provider = 'gemini'
    ): void {
        try {
            $insertPayload = [
                'user_id' => $userId,
                'subscriber_id' => $subscriberId,
                'task_type' => $taskType,
                'marketplace' => null,
                'provider' => $provider,
                'model' => $model,
                'request_payload' => $requestPayload,
                'response_text' => null,
                'response_images' => $responseImages,
                'response_type' => $responseType,
                'images_count' => $imagesCount,
                'input_tokens' => (int) (data_get($usage, 'promptTokenCount') ?: 0),
                'output_tokens' => (int) (data_get($usage, 'candidatesTokenCount') ?: 0),
                'prompt_tokens' => (int) (data_get($usage, 'promptTokenCount') ?: 0),
                'candidates_tokens' => (int) (data_get($usage, 'candidatesTokenCount') ?: 0),
                'total_tokens' => (int) (data_get($usage, 'totalTokenCount') ?: 0),
                'status_code' => $statusCode,
                'error_message' => $errorMessage,
                'created_at' => now(),
            ];

            AiRequestLog::create($this->filterInsertByExistingColumns($insertPayload));
        } catch (Throwable $exception) {
            Log::error('AI image DB log write failed', [
                'exception' => $exception->getMessage(),
                'user_id' => $userId,
                'subscriber_id' => $subscriberId,
                'task_type' => $taskType,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDbRequestPayload(array $requestPayload, array $response): array
    {
        $payload = $this->sanitizePayload($requestPayload);
        $outboundPayload = data_get($response, 'request_payload');
        $provider = (string) data_get($response, 'provider', 'gemini');

        if (is_array($outboundPayload)) {
            if ($provider === 'grok') {
                $payload['_grok_request_payload'] = $outboundPayload;
            } else {
                $payload['_gemini_request_payload'] = $outboundPayload;
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        if (isset($payload['image']) && is_string($payload['image'])) {
            $payload['image'] = 'base64_length:' . mb_strlen($payload['image']);
        }

        if (isset($payload['images']) && is_array($payload['images'])) {
            $payload['images'] = array_values(array_map(
                static fn (mixed $image): string => is_string($image) ? 'base64_length:' . mb_strlen($image) : 'unsupported',
                $payload['images']
            ));
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function filterInsertByExistingColumns(array $payload): array
    {
        static $availableColumns = null;

        if (! is_array($availableColumns)) {
            try {
                $availableColumns = Schema::getColumnListing('ai_request_logs');
            } catch (Throwable) {
                return $payload;
            }
        }

        if ($availableColumns === []) {
            return $payload;
        }

        $allowed = array_flip($availableColumns);

        return array_filter(
            $payload,
            static fn (mixed $value, string $key): bool => isset($allowed[$key]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function resolveModelFromResponse(array $response): ?string
    {
        $model = trim((string) (
            data_get($response, 'model')
            ?: data_get($response, 'data.model')
            ?: data_get($response, 'data.modelVersion')
            ?: ''
        ));

        return $model === '' ? null : $model;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function prepareResponseImagesForLog(array $images, int $userId): array
    {
        $prepared = [];

        if ($userId <= 0) {
            return $prepared;
        }

        foreach ($images as $item) {
            $base64 = (string) ($item['base64'] ?? '');
            if ($base64 === '') {
                continue;
            }

            $mimeType = (string) ($item['mime_type'] ?? 'image/png');
            $imageInput = 'data:' . $mimeType . ';base64,' . $base64;

            try {
                $storedImage = $this->aiMediaStorageService->storeImageAndGetSignedUrl($imageInput, $userId);

                $prepared[] = [
                    'mime_type' => (string) ($storedImage['mime_type'] ?? $mimeType),
                    'path' => (string) ($storedImage['path'] ?? ''),
                    'url' => (string) ($storedImage['signed_url'] ?? $storedImage['url_preview'] ?? ''),
                    'url_preview' => (string) ($storedImage['url_preview'] ?? ''),
                    'size' => (int) ($storedImage['size'] ?? 0),
                ];
            } catch (Throwable $exception) {
                Log::warning('AI image generated storage failed', [
                    'user_id' => $userId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return array_values(array_filter($prepared, static fn (array $item): bool => ($item['path'] ?? '') !== ''));
    }

    public function validateInputImageSize(string $imageInput): ?string
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
            return 'Размер каждого изображения не должен превышать 10MB';
        }

        return null;
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