<?php

namespace App\Services\Grok;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GrokImageApiClient
{
    public function generateOrEditImage(string $prompt, array $images = [], array $options = []): array
    {
        $apiKey = (string) config('services.grok.api_key');
        if ($apiKey === '') {
            return [
                'success' => false,
                'status' => 503,
                'messages' => ['Не задан GROK_API_KEY'],
                'provider' => 'grok',
                'model' => null,
                'data' => null,
                'request_payload' => null,
            ];
        }

        $baseUrl = rtrim((string) config('services.grok.base_url', 'https://api.x.ai'), '/');
        $model = (string) config('services.grok.image_model', 'grok-imagine-image-quality');

        $normalizedImages = $this->normalizeImages($images);
        $useEditEndpoint = $normalizedImages !== [];

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
        ];

        $aspectRatio = trim((string) ($options['aspect_ratio'] ?? $options['aspectRatio'] ?? ''));
        if ($aspectRatio !== '') {
            $payload['aspect_ratio'] = $aspectRatio;
        }

        if ($useEditEndpoint) {
            $payload['images'] = array_map(static fn(string $imageUrl): array => [
                'type' => 'image_url',
                'url' => $imageUrl,
            ], $normalizedImages);
            $endpoint = '/v1/images/edits';
        } else {
            $endpoint = '/v1/images/generations';
        }

        $sanitizedRequestPayload = $this->sanitizePayloadForLog($payload);

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->timeout(120);

            $proxy = (string) config('services.proxy', '');
            if ($proxy !== '') {
                $request = $request->withOptions(['proxy' => $proxy]);
            }

            $response = $request->post($baseUrl . $endpoint, $payload);
            $data = $response->json();

            if (! is_array($data)) {
                $data = ['raw' => $response->body()];
            }

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'messages' => $response->successful() ? [] : [(string) (data_get($data, 'error.message') ?: 'Ошибка Grok Image API')],
                'provider' => 'grok',
                'model' => (string) ($data['model'] ?? $model),
                'data' => $data,
                'request_payload' => $sanitizedRequestPayload,
            ];
        } catch (\Throwable $exception) {
            Log::error('Grok image request failed', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 503,
                'messages' => ['Не удалось обратиться к Grok Image API'],
                'provider' => 'grok',
                'model' => $model,
                'data' => null,
                'request_payload' => $sanitizedRequestPayload,
            ];
        }
    }

    public function extractImages(array $response): array
    {
        $items = (array) ($response['data'] ?? []);
        $result = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $b64 = trim((string) ($item['b64_json'] ?? $item['base64'] ?? ''));
            if ($b64 !== '') {
                $mime = $this->detectMimeByBase64($b64) ?? 'image/png';
                $result[] = [
                    'mime_type' => $mime,
                    'base64' => $b64,
                    'data_uri' => 'data:' . $mime . ';base64,' . $b64,
                ];
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $downloaded = $this->downloadImageAsBase64($url);
            if ($downloaded === null) {
                continue;
            }

            $result[] = $downloaded;
        }

        return $result;
    }

    private function normalizeImages(array $images): array
    {
        $normalized = [];

        foreach ($images as $image) {
            if (! is_string($image)) {
                continue;
            }

            $trimmed = trim($image);
            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, 'data:')) {
                $normalized[] = $trimmed;
                continue;
            }

            if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
                $downloaded = $this->downloadImageAsBase64($trimmed);
                if ($downloaded !== null) {
                    $normalized[] = (string) $downloaded['data_uri'];
                }
                continue;
            }

            if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $trimmed) === 1) {
                $base64 = preg_replace('/\s+/', '', $trimmed) ?? '';
                if ($base64 !== '') {
                    $normalized[] = 'data:image/jpeg;base64,' . $base64;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    private function downloadImageAsBase64(string $url): ?array
    {
        try {
            $request = Http::timeout(60);

            $proxy = (string) config('services.proxy', '');
            if ($proxy !== '') {
                $request = $request->withOptions(['proxy' => $proxy]);
            }

            $response = $request->get($url);
            if (! $response->successful()) {
                return null;
            }

            $binary = $response->body();
            if ($binary === '') {
                return null;
            }

            $contentType = trim((string) $response->header('Content-Type', ''));
            $mime = $contentType !== '' ? explode(';', $contentType)[0] : null;
            $mimeType = $this->normalizeMime($mime) ?? $this->detectMimeByBinary($binary) ?? 'image/jpeg';
            $base64 = base64_encode($binary);

            return [
                'mime_type' => $mimeType,
                'base64' => $base64,
                'data_uri' => 'data:' . $mimeType . ';base64,' . $base64,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function detectMimeByBinary(string $binary): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($binary);

        return $this->normalizeMime(is_string($mime) ? $mime : null);
    }

    private function detectMimeByBase64(string $base64): ?string
    {
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            return null;
        }

        return $this->detectMimeByBinary($binary);
    }

    private function normalizeMime(?string $mime): ?string
    {
        $value = mb_strtolower(trim((string) $mime));

        return match ($value) {
            'image/jpeg', 'image/jpg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
            'image/gif' => 'image/gif',
            default => null,
        };
    }

    private function sanitizePayloadForLog(array $payload): array
    {
        $sanitized = $payload;

        if (isset($sanitized['images']) && is_array($sanitized['images'])) {
            $sanitized['images'] = array_map(static function ($image): array {
                if (! is_array($image)) {
                    return ['invalid' => true];
                }

                $url = (string) ($image['url'] ?? '');
                if (str_starts_with($url, 'data:')) {
                    $image['url'] = 'data_uri_length:' . mb_strlen($url);
                }

                return $image;
            }, $sanitized['images']);
        }

        return $sanitized;
    }
}
