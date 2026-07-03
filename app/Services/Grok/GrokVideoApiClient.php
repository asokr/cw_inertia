<?php

namespace App\Services\Grok;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GrokVideoApiClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $videoModel;
    private mixed $proxy;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $videoModel = null,
        mixed $proxy = null
    ) {
        $this->apiKey = (string) ($apiKey ?? config('services.grok.api_key'));
        $this->baseUrl = rtrim((string) ($baseUrl ?? config('services.grok.base_url', 'https://api.x.ai')), '/');
        $this->videoModel = (string) ($videoModel ?? config('services.grok.video_model', 'grok-imagine-video'));
        $this->proxy = $proxy ?? config('services.proxy');
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

                $payload['reference_images'] = array_map(function (string $imageInput): array {
                    return [
                        'url' => $this->normalizeImageInputToDataUri($imageInput),
                    ];
                }, $referenceImages);

                return $this->request('POST', '/v1/videos/generations', $payload);
            }

            $imageUrl = trim((string) ($options['image_url'] ?? ''));

            if ($imageUrl === '') {
                $imageInput = (string) ($options['image'] ?? '');
                if ($imageInput !== '') {
                    $imageUrl = $this->normalizeImageInput($imageInput);
                }
            }

            if ($imageUrl === '') {
                return [
                    'success' => false,
                    'status' => 422,
                    'messages' => ['Для генерации видео из изображения требуется поле image_url'],
                    'data' => [],
                    'request_payload' => $this->sanitizePayloadForLog($payload),
                ];
            }

            if (! str_starts_with($imageUrl, 'https://') && ! str_starts_with($imageUrl, 'http://') && ! str_starts_with($imageUrl, 'data:')) {
                return [
                    'success' => false,
                    'status' => 422,
                    'messages' => ['Для генерации видео из изображения требуется HTTP(S) ссылка или data URI'],
                    'data' => [],
                    'request_payload' => $this->sanitizePayloadForLog($payload),
                ];
            }

            $payload['image'] = ['url' => $imageUrl];
        }

        return $this->request('POST', '/v1/videos/generations', $payload);
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

            $data = $response->json();
            if (! is_array($data)) {
                $data = ['raw' => $response->body()];
            }

            $sanitizedResponsePayload = $this->sanitizeResponseForLog($data);

            $success = $response->successful();
            $errorField = data_get($data, 'error');
            $message = trim((string) (
                data_get($data, 'error.message')
                ?? (is_string($errorField) ? $errorField : null)
                ?? data_get($data, 'message')
                ?? ''
            ));

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
                'payload_pretty' => $this->formatPayloadForLog($payload ?? []),
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
        if ($this->proxy === null || $this->proxy === '') {
            return [];
        }

        return ['proxy' => $this->proxy];
    }

    private function normalizeImageInput(string $image): string
    {
        $trimmed = trim($image);

        if ($trimmed === '') {
            throw new RuntimeException('Изображение не передано');
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://') || str_starts_with($trimmed, 'data:')) {
            return $trimmed;
        }

        if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $trimmed) === 1) {
            $base64 = preg_replace('/\s+/', '', $trimmed) ?? $trimmed;

            return 'data:image/jpeg;base64,' . $base64;
        }

        throw new RuntimeException('Неподдерживаемый формат изображения для генерации видео');
    }

    private function normalizeImageInputToDataUri(string $image): string
    {
        $trimmed = trim($image);

        if ($trimmed === '') {
            throw new RuntimeException('Изображение не передано');
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            $response = Http::timeout(20)->get($trimmed);
            if (! $response->successful()) {
                throw new RuntimeException('Не удалось скачать изображение по ссылке');
            }

            $contentType = strtolower(trim((string) $response->header('Content-Type', '')));
            $mimeType = $this->normalizeImageMimeType($contentType !== '' ? explode(';', $contentType)[0] : '');
            if ($mimeType === null) {
                throw new RuntimeException('Неподдерживаемый формат изображения для генерации видео');
            }

            return 'data:' . $mimeType . ';base64,' . base64_encode($response->body());
        }

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
            if ($decoded === false) {
                throw new RuntimeException('Некорректный base64 изображения');
            }

            return 'data:' . $mimeType . ';base64,' . base64_encode($decoded);
        }

        if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $trimmed) === 1) {
            $base64 = preg_replace('/\s+/', '', $trimmed) ?? $trimmed;
            $decoded = base64_decode($base64, true);
            if ($decoded === false) {
                throw new RuntimeException('Некорректный base64 изображения');
            }

            return 'data:image/jpeg;base64,' . base64_encode($decoded);
        }

        throw new RuntimeException('Неподдерживаемый формат изображения для генерации видео');
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
