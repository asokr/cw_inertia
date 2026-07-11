<?php

namespace App\Http\Controllers\Api\Subscriber\Ai;

use Throwable;
use App\Enums\AiTaskType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\AiRequestLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Services\Gemini\GeminiApiClient;
use App\Services\Ai\AiMediaStorageService;
use App\Services\OpenAi\OpenAiTextFallbackClient;
use App\Models\Subscribers\SubscribersSubscriptions;

class GeminiController extends Controller
{
    private const PUBLIC_ERROR_MESSAGE = 'Ошибка при работе. Попробуйте позже.';
    private const HIGH_DEMAND_PUBLIC_ERROR_MESSAGE = 'ИИ сейчас занят большим объёмом задач. Попробуйте чуть позже — всё обязательно будет работать.';
    private const MAX_INPUT_IMAGE_BYTES = 10485760;

    public function __construct(
        private readonly GeminiApiClient $geminiApiClient,
        private readonly AiMediaStorageService $aiMediaStorageService,
        private readonly OpenAiTextFallbackClient $openAiTextFallbackClient,
    ) {}

    public function marketplace(Request $request): JsonResponse
    {
        $allowedTaskTypes = [
            AiTaskType::GENERATE_DESCRIPTION->value,
            AiTaskType::REWRITE_TEXT->value,
            AiTaskType::REWRITE_OZON->value,
            AiTaskType::REWRITE_WB->value,
            AiTaskType::ADAPT_WB->value,
            AiTaskType::ADAPT_OZON->value,
            AiTaskType::GENERATE_OZON_RICH->value,
            AiTaskType::RICH_DESCRIPTION->value,
        ];

        $validator = Validator::make($request->all(), [
            'task_type' => 'required|string|in:' . implode(',', $allowedTaskTypes),
            'marketplace' => 'nullable|string|in:wb,ozon',
            'title' => 'nullable|string|max:500',
            'description' => 'nullable|string|max:10000',
            'features' => 'nullable|string|max:10000',
            'image' => 'nullable|string',
        ], [
            'task_type.required' => 'Не указан тип задачи',
            'task_type.in' => 'Указан недопустимый тип задачи',
            'marketplace.in' => 'Поддерживаются только wb и ozon',
        ]);

        $validator->after(function ($validator) use ($request) {
            $taskType = (string) $request->input('task_type');

            if ($taskType === AiTaskType::GENERATE_DESCRIPTION->value) {
                $hasText = trim((string) $request->input('title', '')) !== '' || trim((string) $request->input('features', '')) !== '';
                $hasImage = trim((string) $request->input('image', '')) !== '';

                if (! $hasText && ! $hasImage) {
                    $validator->errors()->add('title', 'Для генерации описания передайте название/характеристики и/или изображение');
                }

                $singleImageError = $this->validateInputImageSize((string) $request->input('image', ''));
                if ($singleImageError !== null) {
                    $validator->errors()->add('image', $singleImageError);
                }
            }

            if (in_array($taskType, [
                AiTaskType::REWRITE_TEXT->value,
                AiTaskType::REWRITE_OZON->value,
                AiTaskType::REWRITE_WB->value,
                AiTaskType::ADAPT_WB->value,
                AiTaskType::ADAPT_OZON->value,
                AiTaskType::GENERATE_OZON_RICH->value,
                AiTaskType::RICH_DESCRIPTION->value,
            ], true)) {
                if (trim((string) $request->input('description', '')) === '') {
                    $validator->errors()->add('description', 'Для этой задачи нужно передать исходный текст в поле description');
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
        $subscriberId = (int) data_get($user, 'subscriber.id');

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
        $requiredTextLimit = $this->requiresTextLimit($taskType) ? 1 : 0;

        if (! $this->hasEnoughLimit($subscription, 'ai_text_query', $requiredTextLimit)) {
            return response()->json([
                'success' => false,
                'messages' => ['Недостаточно лимита AI_TEXT_QUERY'],
            ], 402);
        }

        try {
            return $this->handleTextTask($request, $subscription, $user?->id, $subscriberId);
        } catch (Throwable $exception) {
            $publicMessage = $this->resolvePublicErrorMessage($exception->getMessage(), 500);

            $this->logRequest(
                userId: $user?->id,
                subscriberId: $subscriberId,
                taskType: $taskType,
                marketplace: $request->input('marketplace'),
                requestPayload: $this->sanitizePayload($request->all()),
                responseType: null,
                imagesCount: 0,
                usage: null,
                model: null,
                statusCode: 500,
                errorMessage: $exception->getMessage()
            );

            return response()->json([
                'success' => false,
                'messages' => [$publicMessage],
            ], 200);
        }
    }

    private function handleTextTask(Request $request, SubscribersSubscriptions $subscription, ?int $userId, int $subscriberId): JsonResponse
    {
        $taskType = (string) $request->input('task_type');
        $marketplace = $request->input('marketplace');
        $provider = 'gemini';

        $systemInstruction = $this->resolveSystemInstruction($taskType, $marketplace);
        $prompt = $this->buildTextPrompt($request, $taskType);

        if (trim((string) $request->input('image', '')) !== '') {
            $response = $this->geminiApiClient->generateProWithImage(
                prompt: $prompt,
                image: (string) $request->input('image'),
                options: [
                    'systemInstruction' => $systemInstruction,
                ]
            );
        } else {
            $response = $this->geminiApiClient->generateProText(
                prompt: $prompt,
                options: [
                    'systemInstruction' => $systemInstruction,
                ]
            );
        }

        if (! ($response['success'] ?? false) && $this->shouldFallbackFromGemini($response)) {
            Log::warning('Gemini text request failed, trying GPT fallback', [
                'status' => $response['status'] ?? null,
                'message' => $response['messages'][0] ?? null,
            ]);

            $fallbackResponse = $this->openAiTextFallbackClient->generateText(
                prompt: $prompt,
                systemInstruction: $systemInstruction,
                imageInput: trim((string) $request->input('image', '')) !== '' ? (string) $request->input('image') : null
            );

            if ($fallbackResponse['success'] ?? false) {
                $response = $fallbackResponse;
                $provider = 'gpt';
            } else {
                Log::warning('GPT fallback request failed', [
                    'status' => $fallbackResponse['status'] ?? null,
                    'message' => $fallbackResponse['messages'][0] ?? null,
                ]);
            }
        }

        if (! ($response['success'] ?? false)) {
            $message = (string) (($response['messages'][0] ?? null) ?: 'Ошибка AI API');
            $statusCode = (int) ($response['status'] ?? 503);
            $publicMessage = $this->resolvePublicErrorMessage($message, $statusCode);

            $this->logRequest(
                userId: $userId,
                subscriberId: $subscriberId,
                taskType: $taskType,
                marketplace: $marketplace,
                requestPayload: $this->buildDbRequestPayload($request->all(), $response),
                responseType: 'text',
                imagesCount: 0,
                usage: $this->extractUsageForProvider($response, $provider),
                model: $this->resolveModelFromResponse($response),
                statusCode: $statusCode,
                errorMessage: $message,
                provider: $provider,
            );

            return response()->json([
                'success' => false,
                'messages' => [$publicMessage],
            ], 200);
        }

        $content = $this->extractTextByProvider((array) ($response['data'] ?? []), $provider);
        $content = $this->sanitizeTextTaskContent($content, $taskType);

        if ($content === '') {
            return response()->json([
                'success' => false,
                'messages' => ['AI не вернул текстовый ответ'],
            ], 200);
        }

        if (! $this->consumeLimit($subscription, 'ai_text_query', 1)) {
            return response()->json([
                'success' => false,
                'messages' => ['Не удалось списать лимит AI_TEXT_QUERY'],
            ], 200);
        }

        $limits = $this->getLimits($subscription->fresh());

        $this->logRequest(
            userId: $userId,
            subscriberId: $subscriberId,
            taskType: $taskType,
            marketplace: $marketplace,
            requestPayload: $this->buildDbRequestPayload($request->all(), $response),
            responseType: 'text',
            imagesCount: 0,
            usage: $this->extractUsageForProvider($response, $provider),
            model: $this->resolveModelFromResponse($response),
            statusCode: 200,
            errorMessage: null,
            responseText: $content,
            provider: $provider,
        );

        return response()->json([
            'success' => true,
            'type' => 'text',
            'content' => $content,
            'limits' => $limits,
        ], 200);
    }

    private function resolveSystemInstruction(string $taskType, ?string $marketplace): string
    {
        return match ($taskType) {
            AiTaskType::GENERATE_DESCRIPTION->value => 'Ты эксперт по карточкам товаров на маркетплейсах. Пиши ясно, выгодно и без воды. Возвращай только текст описания.',
            AiTaskType::REWRITE_TEXT->value => 'Перепиши текст так, чтобы повысить продаваемость, сохранив факты и пользу.',
            AiTaskType::REWRITE_OZON->value => 'Адаптируй текст карточки под Ozon: информативно и с понятными преимуществами. Возвращай только готовый текст карточки, без пояснений, без рассуждений, без вступлений вроде "вот адаптированный текст".',
            AiTaskType::REWRITE_WB->value => 'Адаптируй текст карточки под Wildberries: фокус на выгоды. Строго верни только сплошной текст без абзацев, без списков, без эмодзи/смайлов и без markdown/html. Никаких приветствий, рассуждений и выводов.',
            AiTaskType::ADAPT_WB->value => 'Адаптируй текст карточки под Wildberries: фокус на выгоды. Строго верни только сплошной текст без абзацев, без списков, без эмодзи/смайлов и без markdown/html. Никаких приветствий, рассуждений и выводов.',
            AiTaskType::ADAPT_OZON->value => 'Адаптируй текст карточки под Ozon: информативно и с понятными преимуществами. Возвращай только готовый текст карточки, без пояснений, без рассуждений, без вступлений вроде "вот адаптированный текст".',
            AiTaskType::GENERATE_OZON_RICH->value,
            AiTaskType::RICH_DESCRIPTION->value => 'Сгенерируй только HTML для Rich Description Ozon, без markdown и без пояснений вне HTML.',
            default => $marketplace === 'ozon'
                ? 'Пиши как эксперт по карточкам товаров для Ozon.'
                : 'Пиши как эксперт по карточкам товаров для Wildberries. Пиши только готовый сплошной текст без абзацев, без списков, без эмодзи/смайлов, без форматирования и markdown.',
        };
    }

    private function buildTextPrompt(Request $request, string $taskType): string
    {
        $title = trim((string) $request->input('title', ''));
        $description = trim((string) $request->input('description', ''));
        $features = trim((string) $request->input('features', ''));
        $marketplace = trim((string) $request->input('marketplace', ''));

        return match ($taskType) {
            AiTaskType::GENERATE_DESCRIPTION->value => trim(implode("\n", array_filter([
                'Сформируй продающее описание товара для маркетплейса' . ($marketplace ? ' ' . strtoupper($marketplace) : '') . '.',
                $title !== '' ? 'Название: ' . $title : null,
                $features !== '' ? 'Характеристики: ' . $features : null,
                $description !== '' ? 'Дополнительный контекст: ' . $description : null,
                'Выведи готовый текст без служебных комментариев.',
            ]))),
            AiTaskType::REWRITE_TEXT->value => "Улучшить продаваемость текста:\n" . $description,
            AiTaskType::REWRITE_OZON->value => "Адаптируй текст для Ozon. Верни только итоговый текст карточки товара, без комментариев о том, что ты изменил, без блоков в стиле 'почему этот текст лучше' и без любых служебных пояснений:\n" . $description,
            AiTaskType::REWRITE_WB->value => "Адаптируй текст для Wildberries. Верни только в виде сплошного текста (без абзацев, без списков любого вида, вообще БЕЗ эмодзи/смайлов, без выделений жирным или заголовков). Никаких пояснений в начале или в конце:\n" . $description,
            AiTaskType::ADAPT_WB->value => "Адаптируй текст для Wildberries. Верни только в виде сплошного текста (без абзацев, без списков любого вида, вообще БЕЗ эмодзи/смайлов, без выделений жирным или заголовков). Никаких пояснений в начале или в конце:\n" . $description,
            AiTaskType::ADAPT_OZON->value => "Адаптируй текст для Ozon. Верни только итоговый текст карточки товара, без комментариев о том, что ты изменил, без блоков в стиле 'почему этот текст лучше' и без любых служебных пояснений:\n" . $description,
            AiTaskType::GENERATE_OZON_RICH->value,
            AiTaskType::RICH_DESCRIPTION->value => trim(implode("\n", array_filter([
                'Сгенерируй Rich Description (HTML) для Ozon.',
                $title !== '' ? 'Название: ' . $title : null,
                'Исходный текст: ' . $description,
                $features !== '' ? 'Характеристики: ' . $features : null,
                'Верни только HTML.',
            ]))),
            default => $description,
        };
    }

    private function sanitizeTextTaskContent(string $content, string $taskType): string
    {
        if (! in_array($taskType, [
            AiTaskType::REWRITE_OZON->value,
            AiTaskType::ADAPT_OZON->value,
        ], true)) {
            return trim($content);
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", trim($content));

        $normalized = preg_replace('/^\s*вот\s+адаптированн[а-я\s,:-]*\n+/iu', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/^\s*адаптированн[а-я\s,:-]*\n+/iu', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\n+\s*\*+\s*\n+/u', "\n", $normalized) ?? $normalized;

        $cutPattern = '/\n\s*(почему\s+этот\s+текст\s+лучше|почему\s+текст\s+сработает\s+лучше|что\s+улучшено|комментарии\s+к\s+тексту)\s*:?\s*\n?.*$/isu';
        $normalized = preg_replace($cutPattern, '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function requiresTextLimit(string $taskType): bool
    {
        return in_array($taskType, [
            AiTaskType::GENERATE_DESCRIPTION->value,
            AiTaskType::REWRITE_TEXT->value,
            AiTaskType::REWRITE_OZON->value,
            AiTaskType::REWRITE_WB->value,
            AiTaskType::ADAPT_WB->value,
            AiTaskType::ADAPT_OZON->value,
            AiTaskType::GENERATE_OZON_RICH->value,
            AiTaskType::RICH_DESCRIPTION->value,
        ], true);
    }

    private function hasEnoughLimit(SubscribersSubscriptions $subscription, string $limitKey, int $required): bool
    {
        if ($required <= 0) {
            return true;
        }

        $current = (int) ($subscription->getMonthLimit($limitKey) ?: 0);

        return $current >= $required;
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

    private function getLimits(SubscribersSubscriptions $subscription): array
    {
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

    private function sanitizePayload(array $payload): array
    {
        if (isset($payload['image']) && is_string($payload['image'])) {
            $payload['image'] = 'base64_length:' . mb_strlen($payload['image']);
        }

        if (isset($payload['images']) && is_array($payload['images'])) {
            $payload['images'] = array_values(array_map(
                static fn(mixed $image): string => is_string($image) ? 'base64_length:' . mb_strlen($image) : 'unsupported',
                $payload['images']
            ));
        }

        return $payload;
    }

    private function buildDbRequestPayload(array $requestPayload, array $response): array
    {
        $payload = $this->sanitizePayload($requestPayload);
        $outboundPayload = data_get($response, 'request_payload');
        $provider = (string) data_get($response, 'provider', 'gemini');

        if (is_array($outboundPayload)) {
            if ($provider === 'gpt') {
                $payload['_gpt_request_payload'] = $outboundPayload;
            } elseif ($provider === 'grok') {
                $payload['_grok_request_payload'] = $outboundPayload;
            } else {
                $payload['_gemini_request_payload'] = $outboundPayload;
            }
        }

        return $payload;
    }

    private function extractTextByProvider(array $responseData, string $provider): string
    {
        if ($provider === 'gpt') {
            return $this->openAiTextFallbackClient->extractText($responseData);
        }

        return $this->geminiApiClient->extractText($responseData);
    }

    private function extractUsageForProvider(array $response, string $provider): mixed
    {
        if ($provider === 'gpt') {
            return data_get($response, 'data.usage');
        }

        return data_get($response, 'data.usageMetadata');
    }

    private function shouldFallbackFromGemini(array $response): bool
    {
        $statusCode = (int) ($response['status'] ?? 503);
        $message = mb_strtolower(trim((string) (($response['messages'][0] ?? null) ?: '')));

        if ($statusCode === 429 || $statusCode >= 500) {
            return true;
        }

        // Для практической проверки и прод-сценариев переключаемся на fallback
        // и при ошибках авторизации/ключа Gemini.
        if (in_array($statusCode, [400, 401, 403], true)) {
            if (
                str_contains($message, 'api key')
                || str_contains($message, 'key not valid')
                || str_contains($message, 'invalid key')
                || str_contains($message, 'auth')
                || str_contains($message, 'authentication')
                || str_contains($message, 'unauthorized')
                || str_contains($message, 'permission denied')
            ) {
                return true;
            }
        }

        return str_contains($message, 'high demand')
            || str_contains($message, 'spikes in demand')
            || str_contains($message, 'try again later')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'resource exhausted')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'timeout')
            || str_contains($message, 'network')
            || str_contains($message, 'unavailable');
    }

    private function resolvePublicErrorMessage(?string $providerMessage, ?int $statusCode = null): string
    {
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
            || str_contains($message, 'spikes in demand')
            || str_contains($message, 'try again later')
            || str_contains($message, 'temporarily unavailable')
            || str_contains($message, 'resource exhausted')
            || str_contains($message, 'rate limit');
    }

    private function logRequest(
        ?int $userId,
        int $subscriberId,
        string $taskType,
        ?string $marketplace,
        array $requestPayload,
        ?string $responseType,
        int $imagesCount,
        mixed $usage,
        ?string $model,
        int $statusCode,
        ?string $errorMessage,
        ?string $responseText = null,
        ?array $responseImages = null,
        string $provider = 'gemini'
    ): void {
        $inputTokens = 0;
        $outputTokens = 0;

        try {
            $inputTokens = $this->resolveInputTokens($usage, $requestPayload);
            $outputTokens = $this->resolveOutputTokens($usage, $responseText);

            $insertPayload = [
                'user_id' => $userId,
                'subscriber_id' => $subscriberId,
                'task_type' => $taskType,
                'marketplace' => $marketplace,
                'provider' => $provider,
                'model' => $model,
                'request_payload' => $requestPayload,
                'response_text' => $responseText,
                'response_images' => $responseImages,
                'response_type' => $responseType,
                'images_count' => $imagesCount,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'prompt_tokens' => (int) (data_get($usage, 'promptTokenCount') ?: data_get($usage, 'prompt_tokens') ?: 0),
                'candidates_tokens' => (int) (data_get($usage, 'candidatesTokenCount') ?: data_get($usage, 'completion_tokens') ?: 0),
                'total_tokens' => (int) (data_get($usage, 'totalTokenCount') ?: data_get($usage, 'total_tokens') ?: ($inputTokens + $outputTokens)),
                'status_code' => $statusCode,
                'error_message' => $errorMessage,
                'created_at' => now(),
            ];

            AiRequestLog::create($this->filterInsertByExistingColumns($insertPayload));
        } catch (Throwable $exception) {
            Log::error('AI marketplace DB log write failed', [
                'exception' => $exception->getMessage(),
                'user_id' => $userId,
                'subscriber_id' => $subscriberId,
                'task_type' => $taskType,
                'marketplace' => $marketplace,
                'response_type' => $responseType,
                'images_count' => $imagesCount,
                'response_text_length' => mb_strlen((string) $responseText),
                'response_images_count' => is_array($responseImages) ? count($responseImages) : 0,
                'status_code' => $statusCode,
                'error_message' => $errorMessage,
                'usage' => [
                    'promptTokenCount' => (int) (data_get($usage, 'promptTokenCount') ?: 0),
                    'candidatesTokenCount' => (int) (data_get($usage, 'candidatesTokenCount') ?: 0),
                    'totalTokenCount' => (int) (data_get($usage, 'totalTokenCount') ?: 0),
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                ],
                'request_payload' => $requestPayload,
            ]);
        }
    }

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
            static fn(mixed $value, string $key): bool => isset($allowed[$key]),
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

    private function resolveInputTokens(mixed $usage, array $requestPayload): int
    {
        $inputTokens = (int) (data_get($usage, 'promptTokenCount') ?: data_get($usage, 'prompt_tokens') ?: 0);
        if ($inputTokens > 0) {
            return $inputTokens;
        }

        $serialized = json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        return $this->estimateTokensByLength($serialized);
    }

    private function resolveOutputTokens(mixed $usage, ?string $responseText): int
    {
        $outputTokens = (int) (data_get($usage, 'candidatesTokenCount') ?: data_get($usage, 'completion_tokens') ?: 0);
        if ($outputTokens > 0) {
            return $outputTokens;
        }

        return $this->estimateTokensByLength((string) ($responseText ?? ''));
    }

    private function estimateTokensByLength(string $text): int
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0;
        }

        return max(1, (int) ceil(mb_strlen($trimmed) / 4));
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
