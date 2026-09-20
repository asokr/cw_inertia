<?php

namespace App\Services\Grok;

use App\Services\Ai\AiMediaStorageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GrokVideoApiClient
{
    public const PAYLOAD_TOO_LARGE_MESSAGE = 'The POST data is too large';

    private string $apiKey;
    private string $baseUrl;
    private string $videoModel;
    private mixed $proxy;
    private ?AiMediaStorageService $mediaStorage;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $videoModel = null,
        mixed $proxy = null,
        ?AiMediaStorageService $mediaStorage = null,
    ) {
        $this->apiKey = (string) ($apiKey ?? config('services.grok.api_key'));
        $this->baseUrl = rtrim((string) ($baseUrl ?? config('services.grok.base_url', 'https://api.x.ai')), '/');
        $this->videoModel = (string) ($videoModel ?? config('services.grok.video_model', 'grok-imagine-video'));
        $this->proxy = $proxy ?? config('services.proxy');
        $this->mediaStorage = $mediaStorage;
    }

    public function startGeneration(string $taskType, string $prompt, array $options = []): array
    {
        $payload = [
            'model' => $this->videoModel,
            'prompt' => $prompt,
        ];

        if (isset($options['duration'])) {
            $payload['duration'] = (int) $options['duration'];
        }

        if (isset($options['resolution']) && is_string($options['resolution']) && $options['resolution'] !== '') {
            $payload['resolution'] = $options['resolution'];
        }

        if (isset($options['aspect_ratio']) && is_string($options['aspect_ratio']) && $options['aspect_ratio'] !== '') {
            $payload['aspect_ratio'] = $options['aspect_ratio'];
        }

        if ($this->apiKey === '') {
            return [
                'success' => false,
                'status' => 500,
                'messages' => ['Не задан GROK_API_KEY'],
                'data' => [],
                'request_payload' => $this->sanitizePayloadForLog($payload),
            ];
        }

        if ($taskType === 'generate_video_from_image') {
            $referenceImages = $this->normalizeReferenceImagesOption($options['reference_images'] ?? []);
            if ($referenceImages !== []) {
                if (count($referenceImages) > 7) {
                    return [
                        'success' => false,
                        'status' => 422,
                        'messages' => ['Можно передать не более 7 изображений'],
                        'data' => [],
                        'request_payload' => $this->sanitizePayloadForLog($payload),
                    ];
                }

                try {
                    $payload['reference_images'] = array_map(
                        fn (string $imageInput): array => $this->resolveProviderImage($imageInput),
                        $referenceImages
                    );
                } catch (Throwable $exception) {
                    return $this->failureFromException($exception, $payload);
                }

                return $this->postGeneration($payload);
            }

            $imageInput = trim((string) ($options['image_url'] ?? $options['image'] ?? ''));

            if ($imageInput === '') {
                return [
                    'success' => false,
                    'status' => 422,
                    'messages' => ['Для генерации видео из изображения требуется поле image_url'],
                    'data' => [],
                    'request_payload' => $this->sanitizePayloadForLog($payload),
                ];
            }

            try {
                $payload['image'] = $this->resolveProviderImage($imageInput);
            } catch (Throwable $exception) {
                return $this->failureFromException($exception, $payload);
            }
        }

        return $this->postGeneration($payload);
    }

    /**
     * Редактирование ролика: POST /v1/videos/edits, исходник через Files API.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function startEdit(string $prompt, array $options = []): array
    {
        $payload = [
            'model' => $this->videoModel,
            'prompt' => $prompt,
        ];

        if ($this->apiKey === '') {
            return [
                'success' => false,
                'status' => 500,
                'messages' => ['Не задан GROK_API_KEY'],
                'data' => [],
                'request_payload' => $this->sanitizePayloadForLog($payload),
            ];
        }

        $videoInput = trim((string) ($options['video'] ?? $options['video_url'] ?? ''));
        if ($videoInput === '') {
            return [
                'success' => false,
                'status' => 422,
                'messages' => ['Для редактирования видео нужно передать ролик'],
                'data' => [],
                'request_payload' => $this->sanitizePayloadForLog($payload),
            ];
        }

        try {
            $payload['video'] = $this->resolveProviderVideo($videoInput);
        } catch (Throwable $exception) {
            return $this->failureFromException($exception, $payload);
        }

        return $this->postEdit($payload);
    }

    public function getGeneration(string $requestId): array
    {
        return $this->request('GET', '/v1/videos/' . rawurlencode($requestId));
    }

    private function request(string $method, string $uri, ?array $payload = null): array
    {
        $url = $this->baseUrl . $uri;
        $sanitizedRequestPayload = $this->sanitizePayloadForLog($payload ?? []);

        if ($this->apiKey === '') {
            return [
                'success' => false,
                'status' => 500,
                'messages' => ['Не задан GROK_API_KEY'],
                'data' => [],
                'request_payload' => $sanitizedRequestPayload,
            ];
        }

        try {
            $request = Http::acceptJson()
                ->withToken($this->apiKey)
                ->withOptions($this->buildHttpOptions())
                ->timeout(180);



            if ($method === 'POST') {
                $request = $request->asJson();
                $response = $request->post($url, $payload ?? []);
            } else {
                $response = $request->get($url);
            }

            $rawBody = (string) $response->body();
            $data = $response->json();
            if (! is_array($data)) {
                $data = ['raw' => $rawBody];
            }

            $sanitizedResponsePayload = $this->sanitizeResponseForLog($data);

            $success = $response->successful();
            $message = $this->extractProviderMessage($data, $rawBody, $response->status());

            if (! $success) {
                Log::warning('Grok video API returned non-success response', [
                    'url' => $url,
                    'method' => $method,
                    'status' => $response->status(),
                    'message' => $message,
                    'response_payload' => $sanitizedResponsePayload,
                ]);
            }

            return [
                'success' => $success,
                'status' => $response->status(),
                'messages' => $success ? [] : [$message !== '' ? $message : 'Ошибка Grok API'],
                'data' => $data,
                'request_payload' => $sanitizedRequestPayload,
                'response_payload' => $sanitizedResponsePayload,
            ];
        } catch (Throwable $exception) {
            Log::error('Grok video request failed', [
                'url' => $url,
                'method' => $method,
                'exception' => $exception->getMessage(),
                'payload_pretty' => $this->formatPayloadForLog($this->sanitizePayloadForLog($payload ?? [])),
            ]);

            return [
                'success' => false,
                'status' => 500,
                'messages' => [$exception->getMessage()],
                'data' => [],
                'request_payload' => $sanitizedRequestPayload,
                'response_payload' => [
                    'exception' => $exception->getMessage(),
                ],
            ];
        }
    }

    private function buildHttpOptions(): array
    {
        $options = [];

        if ($this->proxy !== null && $this->proxy !== '') {
            $options['proxy'] = $this->proxy;
        }

        if (! (bool) config('services.grok.http_verify', true)) {
            $options['verify'] = false;
        }

        return $options;
    }

    /**
     * Публичный URL оставляем как url; бинарные картинки грузим в Files API,
     * иначе JSON с base64 упирается в лимит шлюза xAI (~4 МБ, ошибка POST data is too large).
     *
     * @return array{file_id: string}|array{url: string}
     */
    private function resolveProviderImage(string $imageInput): array
    {
        $trimmed = trim($imageInput);

        if ($trimmed === '') {
            throw new RuntimeException('Изображение не передано');
        }

        // Сохранённые кадры из истории приходят как /panel/ai/media/... — читаем с диска, не как публичный URL.
        if ($this->mediaStorage()->isPanelMediaInput($trimmed)) {
            [$binary, $mimeType] = $this->decodeStoredMedia($trimmed);

            return ['file_id' => $this->uploadImageFile($binary, $mimeType)];
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return ['url' => $trimmed];
        }

        [$binary, $mimeType] = $this->decodeImageBinary($trimmed);

        return ['file_id' => $this->uploadImageFile($binary, $mimeType)];
    }

    /**
     * @return array{0: string, 1: string} [binary, mimeType]
     */
    private function decodeStoredMedia(string $imageInput): array
    {
        [$mimeType, $base64] = $this->mediaStorage()->resolveImageInlineData($imageInput);
        $decoded = base64_decode($base64, true);
        if ($decoded === false || $decoded === '') {
            throw new RuntimeException('Некорректный файл изображения');
        }

        $this->assertImageSize($decoded);

        return [$decoded, $mimeType];
    }

    private function mediaStorage(): AiMediaStorageService
    {
        return $this->mediaStorage ??= app(AiMediaStorageService::class);
    }

    /**
     * @return array{0: string, 1: string} [binary, mimeType]
     */
    private function decodeImageBinary(string $image): array
    {
        $trimmed = trim($image);

        if (str_starts_with($trimmed, 'data:')) {
            if (! preg_match('/^data:(?<mime>[-\w.+\/]+);base64,(?<data>.+)$/s', $trimmed, $matches)) {
                throw new RuntimeException('Некорректный data URI изображения');
            }

            $mimeType = $this->normalizeImageMimeType((string) ($matches['mime'] ?? ''));
            if ($mimeType === null) {
                throw new RuntimeException('Неподдерживаемый формат изображения для генерации видео');
            }

            $data = preg_replace('/\s+/', '', (string) ($matches['data'] ?? '')) ?: '';
            $decoded = base64_decode($data, true);
            if ($decoded === false || $decoded === '') {
                throw new RuntimeException('Некорректный base64 изображения');
            }

            $this->assertImageSize($decoded);

            return [$decoded, $mimeType];
        }

        if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $trimmed) === 1) {
            $base64 = preg_replace('/\s+/', '', $trimmed) ?? $trimmed;
            $decoded = base64_decode($base64, true);
            if ($decoded === false || $decoded === '') {
                throw new RuntimeException('Некорректный base64 изображения');
            }

            $this->assertImageSize($decoded);

            return [$decoded, 'image/jpeg'];
        }

        throw new RuntimeException('Неподдерживаемый формат изображения для генерации видео');
    }

    private function uploadImageFile(string $binary, string $mimeType): string
    {
        $this->assertImageSize($binary);

        $expiresAfter = (int) config('services.grok.files_expires_after', 3600);
        $expiresAfter = max(3600, min(2_592_000, $expiresAfter));

        $extension = match ($mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $request = Http::acceptJson()
            ->withToken($this->apiKey)
            ->withOptions($this->buildHttpOptions())
            ->timeout(120)
            ->attach('file', $binary, 'video-input.'.$extension, ['Content-Type' => $mimeType]);

        $response = $request->post($this->baseUrl.'/v1/files', [
            'expires_after' => (string) $expiresAfter,
            'purpose' => 'assistants',
        ]);

        if (! $response->successful()) {
            Log::warning('Grok Files API upload failed', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 1000),
            ]);

            throw new RuntimeException('Не удалось подготовить изображение для генерации видео');
        }

        $fileId = trim((string) ($response->json('id') ?? ''));
        if ($fileId === '') {
            Log::warning('Grok Files API upload returned empty file id', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 1000),
            ]);

            throw new RuntimeException('Не удалось подготовить изображение для генерации видео');
        }

        return $fileId;
    }

    private function postGeneration(array $payload): array
    {
        $oversized = $this->payloadTooLargeResponse($payload);
        if ($oversized !== null) {
            return $oversized;
        }

        return $this->request('POST', '/v1/videos/generations', $payload);
    }

    private function postEdit(array $payload): array
    {
        $oversized = $this->payloadTooLargeResponse($payload);
        if ($oversized !== null) {
            return $oversized;
        }

        return $this->request('POST', '/v1/videos/edits', $payload);
    }

    /**
     * @return array{file_id: string}|array{url: string}
     */
    private function resolveProviderVideo(string $videoInput): array
    {
        $trimmed = trim($videoInput);

        if ($trimmed === '') {
            throw new RuntimeException('Ролик не передан');
        }

        if ($this->mediaStorage()->isPanelMediaInput($trimmed)) {
            [$binary, $mimeType] = $this->decodeStoredVideo($trimmed);

            return ['file_id' => $this->uploadVideoFile($binary, $mimeType)];
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return ['url' => $trimmed];
        }

        [$binary, $mimeType] = $this->decodeVideoBinary($trimmed);

        return ['file_id' => $this->uploadVideoFile($binary, $mimeType)];
    }

    /**
     * @return array{0: string, 1: string} [binary, mimeType]
     */
    private function decodeStoredVideo(string $videoInput): array
    {
        [$binary, $mimeType] = $this->mediaStorage()->resolveVideoBinaryAndMime($videoInput);
        $this->assertSourceVideoSize($binary);

        return [$binary, $mimeType];
    }

    /**
     * @return array{0: string, 1: string} [binary, mimeType]
     */
    private function decodeVideoBinary(string $video): array
    {
        $trimmed = trim($video);

        if (str_starts_with($trimmed, 'data:')) {
            if (! preg_match('/^data:(?<mime>[-\w.+\/]+);base64,(?<data>.+)$/s', $trimmed, $matches)) {
                throw new RuntimeException('Этот ролик не подходит. Загрузите обычный MP4-файл.');
            }

            $mimeType = mb_strtolower(trim((string) ($matches['mime'] ?? '')));
            if ($mimeType !== 'video/mp4') {
                throw new RuntimeException('Этот ролик не подходит. Загрузите обычный MP4-файл.');
            }

            $data = preg_replace('/\s+/', '', (string) ($matches['data'] ?? '')) ?: '';
            $decoded = base64_decode($data, true);
            if ($decoded === false || $decoded === '') {
                throw new RuntimeException('Этот ролик не подходит. Загрузите обычный MP4-файл.');
            }

            $this->assertSourceVideoSize($decoded);

            return [$decoded, 'video/mp4'];
        }

        throw new RuntimeException('Этот ролик не подходит. Загрузите обычный MP4-файл.');
    }

    private function uploadVideoFile(string $binary, string $mimeType): string
    {
        $this->assertSourceVideoSize($binary);

        $expiresAfter = (int) config('services.grok.files_expires_after', 3600);
        $expiresAfter = max(3600, min(2_592_000, $expiresAfter));

        $request = Http::acceptJson()
            ->withToken($this->apiKey)
            ->withOptions($this->buildHttpOptions())
            ->timeout(180)
            ->attach('file', $binary, 'video-input.mp4', ['Content-Type' => $mimeType !== '' ? $mimeType : 'video/mp4']);

        $response = $request->post($this->baseUrl.'/v1/files', [
            'expires_after' => (string) $expiresAfter,
            'purpose' => 'assistants',
        ]);

        if (! $response->successful()) {
            Log::warning('Grok Files API video upload failed', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 1000),
            ]);

            throw new RuntimeException('Не удалось подготовить ролик для редактирования');
        }

        $fileId = trim((string) ($response->json('id') ?? ''));
        if ($fileId === '') {
            Log::warning('Grok Files API video upload returned empty file id', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 1000),
            ]);

            throw new RuntimeException('Не удалось подготовить ролик для редактирования');
        }

        return $fileId;
    }

    private function assertSourceVideoSize(string $binary): void
    {
        $maxBytes = max(1, (int) config('services.ai_media.max_source_video_bytes', 25 * 1024 * 1024));
        if (strlen($binary) > $maxBytes) {
            throw new RuntimeException('Файл слишком большой. Загрузите ролик до 25 МБ.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payloadTooLargeResponse(array $payload): ?array
    {
        $encoded = json_encode($payload);
        $bytes = is_string($encoded) ? strlen($encoded) : 0;
        $limit = max(1, (int) config('services.grok.max_video_json_bytes', 3 * 1024 * 1024));

        if ($bytes <= $limit) {
            return null;
        }

        Log::warning('Grok video JSON payload exceeds gateway limit', [
            'bytes' => $bytes,
            'limit' => $limit,
        ]);

        return [
            'success' => false,
            'status' => 413,
            'messages' => [self::PAYLOAD_TOO_LARGE_MESSAGE],
            'data' => [],
            'request_payload' => $this->sanitizePayloadForLog($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function failureFromException(Throwable $exception, array $payload): array
    {
        $message = $exception->getMessage();
        $status = 422;
        if ($this->isPayloadTooLargeMessage($message)) {
            $status = 413;
        } elseif ($this->isConnectionError($message)) {
            $status = 503;
        }

        Log::warning('Grok video start failed before provider request', [
            'exception' => $message,
            'status' => $status,
        ]);

        return [
            'success' => false,
            'status' => $status,
            'messages' => [$message],
            'data' => [],
            'request_payload' => $this->sanitizePayloadForLog($payload),
        ];
    }

    private function extractProviderMessage(array $data, string $rawBody, int $statusCode): string
    {
        $errorField = data_get($data, 'error');
        $candidates = [
            data_get($data, 'error.message'),
            is_string($errorField) ? $errorField : null,
            data_get($data, 'message'),
            data_get($data, 'raw'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        $trimmedBody = trim($rawBody);
        if ($trimmedBody !== '' && ! str_starts_with($trimmedBody, '{') && ! str_starts_with($trimmedBody, '<')) {
            return $trimmedBody;
        }

        if ($statusCode === 413 || $this->isPayloadTooLargeMessage($rawBody)) {
            return self::PAYLOAD_TOO_LARGE_MESSAGE;
        }

        return '';
    }

    public function isPayloadTooLargeMessage(?string $message): bool
    {
        $normalized = mb_strtolower((string) $message);

        return str_contains($normalized, 'post data is too large')
            || str_contains($normalized, 'request entity too large')
            || str_contains($normalized, 'payload too large')
            || str_contains($normalized, 'request body too large');
    }

    public function isConnectionError(?string $message): bool
    {
        $normalized = mb_strtolower((string) $message);

        return str_contains($normalized, 'curl error')
            || str_contains($normalized, 'ssl certificate')
            || str_contains($normalized, 'self-signed certificate')
            || str_contains($normalized, 'failed to connect')
            || str_contains($normalized, 'could not resolve host')
            || str_contains($normalized, 'connection timed out')
            || str_contains($normalized, 'connection exception');
    }

    private function assertImageSize(string $binary): void
    {
        $maxBytes = max(1, (int) config('services.ai_media.max_image_bytes', 10 * 1024 * 1024));
        if (strlen($binary) > $maxBytes) {
            throw new RuntimeException('Изображение слишком большое');
        }
    }

    private function normalizeImageMimeType(string $mimeType): ?string
    {
        return match (mb_strtolower(trim($mimeType))) {
            'image/jpeg', 'image/jpg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function normalizeReferenceImagesOption(mixed $images): array
    {
        if (! is_array($images)) {
            return [];
        }

        $normalized = [];
        foreach ($images as $image) {
            if (! is_string($image)) {
                continue;
            }

            $trimmed = trim($image);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }

    private function sanitizePayloadForLog(array $payload): array
    {
        if (isset($payload['image_url']) && is_string($payload['image_url']) && str_starts_with($payload['image_url'], 'data:')) {
            $payload['image_url'] = 'data_uri_length:' . mb_strlen($payload['image_url']);
        }

        if (isset($payload['image']['url']) && is_string($payload['image']['url'])) {
            if (str_starts_with($payload['image']['url'], 'data:')) {
                $payload['image']['url'] = 'data_uri_length:' . mb_strlen($payload['image']['url']);
            }
        }

        if (isset($payload['reference_images']) && is_array($payload['reference_images'])) {
            $payload['reference_images'] = array_map(function ($item) {
                if (! is_array($item)) {
                    return $item;
                }

                $url = $item['url'] ?? null;
                if (is_string($url) && str_starts_with($url, 'data:')) {
                    $item['url'] = 'data_uri_length:' . mb_strlen($url);
                }

                return $item;
            }, $payload['reference_images']);
        }

        if (isset($payload['video']['url']) && is_string($payload['video']['url'])) {
            if (str_starts_with($payload['video']['url'], 'data:')) {
                $payload['video']['url'] = 'data_uri_length:' . mb_strlen($payload['video']['url']);
            }
        }

        return $payload;
    }

    private function formatPayloadForLog(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (is_string($encoded)) {
            return $encoded;
        }

        return print_r($payload, true);
    }

    private function sanitizeResponseForLog(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            if (is_string($payload) && mb_strlen($payload) > 5000) {
                return mb_substr($payload, 0, 5000) . '...<truncated>';
            }

            return $payload;
        }

        $sanitized = [];

        foreach ($payload as $key => $value) {
            $sanitized[$key] = $this->sanitizeResponseForLog($value);
        }

        return $sanitized;
    }
}
