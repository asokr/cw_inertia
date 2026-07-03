<?php

namespace App\Services\Gemini;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiApiClient
{
    private string $proModel;
    private string $imageModel;
    private string $apiKey;
    private string $baseUrl;
    private string $apiVersion;
    private mixed $proxy;

    public function __construct(
        string $proModel,
        string $imageModel,
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $apiVersion = null,
        mixed $proxy = null
    ) {
        $this->proModel = $proModel;
        $this->imageModel = $imageModel;
        $this->apiKey = (string) ($apiKey ?? config('services.gemini.api_key'));
        $this->baseUrl = rtrim((string) ($baseUrl ?? config('services.gemini.base_url')), '/');
        $this->apiVersion = (string) ($apiVersion ?? config('services.gemini.api_version', 'v1beta'));
        $this->proxy = $proxy ?? config('services.proxy');

        if ($this->apiKey === '') {
            throw new RuntimeException('Не задан GEMINI_API_KEY');
        }
    }

    public function generateProText(string $prompt, array $options = []): array
    {
        $model = isset($options['model']) ? (string) $options['model'] : $this->proModel;
        unset($options['model']);

        $payload = $this->buildPayload([
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                ],
            ]],
        ], $options);

        return $this->requestGenerateContent($model, $payload);
    }

    public function generateProWithImage(string $prompt, mixed $image, ?string $mimeType = null, array $options = []): array
    {
        $model = isset($options['model']) ? (string) $options['model'] : $this->proModel;
        unset($options['model']);

        [$imageMimeType, $imageData] = $this->normalizeImage($image, $mimeType);

        $payload = $this->buildPayload([
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inlineData' => [
                            'mimeType' => $imageMimeType,
                            'data' => $imageData,
                        ],
                    ],
                ],
            ]],
        ], $options);

        return $this->requestGenerateContent($model, $payload);
    }

    public function generateImage(string $prompt, array $options = []): array
    {
        $defaultGenerationConfig = [
            'responseModalities' => ['IMAGE', 'TEXT'],
        ];

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => array_merge(
                $defaultGenerationConfig,
                is_array($options['generationConfig'] ?? null) ? $options['generationConfig'] : []
            ),
        ];

        if (isset($options['imageConfig']) && is_array($options['imageConfig'])) {
            $payload['generationConfig']['imageConfig'] = $options['imageConfig'];
        }

        if (isset($options['imageGenerationConfig']) && is_array($options['imageGenerationConfig'])) {
            $payload['imageGenerationConfig'] = $options['imageGenerationConfig'];
        }

        $payload = $this->buildPayload($payload, $options);

        return $this->requestGenerateContent($this->imageModel, $payload);
    }

    public function generateImageWithImages(string $prompt, array $images, array $options = []): array
    {
        $defaultGenerationConfig = [
            'responseModalities' => ['IMAGE', 'TEXT'],
        ];

        $parts = [
            ['text' => $prompt],
        ];

        foreach ($images as $image) {
            [$imageMimeType, $imageData] = $this->normalizeImage($image);

            $parts[] = [
                'inlineData' => [
                    'mimeType' => $imageMimeType,
                    'data' => $imageData,
                ],
            ];
        }

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => $parts,
            ]],
            'generationConfig' => array_merge(
                $defaultGenerationConfig,
                is_array($options['generationConfig'] ?? null) ? $options['generationConfig'] : []
            ),
        ];

        if (isset($options['imageConfig']) && is_array($options['imageConfig'])) {
            $payload['generationConfig']['imageConfig'] = $options['imageConfig'];
        }

        if (isset($options['imageGenerationConfig']) && is_array($options['imageGenerationConfig'])) {
            $payload['imageGenerationConfig'] = $options['imageGenerationConfig'];
        }

        $payload = $this->buildPayload($payload, $options);

        return $this->requestGenerateContent($this->imageModel, $payload);
    }

    public function editImage(mixed $image, string $prompt, ?string $mimeType = null, array $options = []): array
    {
        [$imageMimeType, $imageData] = $this->normalizeImage($image, $mimeType);

        $defaultGenerationConfig = [
            'responseModalities' => ['IMAGE', 'TEXT'],
        ];

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    [
                        'inlineData' => [
                            'mimeType' => $imageMimeType,
                            'data' => $imageData,
                        ],
                    ],
                    ['text' => $prompt],
                ],
            ]],
            'generationConfig' => array_merge(
                $defaultGenerationConfig,
                is_array($options['generationConfig'] ?? null) ? $options['generationConfig'] : []
            ),
        ];

        if (isset($options['imageConfig']) && is_array($options['imageConfig'])) {
            $payload['generationConfig']['imageConfig'] = $options['imageConfig'];
        }

        if (isset($options['imageGenerationConfig']) && is_array($options['imageGenerationConfig'])) {
            $payload['imageGenerationConfig'] = $options['imageGenerationConfig'];
        }

        $payload = $this->buildPayload($payload, $options);

        return $this->requestGenerateContent($this->imageModel, $payload);
    }

    public function extractText(array $response): string
    {
        $texts = [];

        foreach ((array) ($response['candidates'] ?? []) as $candidate) {
            foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
                $text = $part['text'] ?? null;
                if (is_string($text) && $text !== '') {
                    $texts[] = $text;
                }
            }
        }

        return trim(implode("\n", $texts));
    }

    public function extractImages(array $response): array
    {
        $images = [];

        foreach ((array) ($response['candidates'] ?? []) as $candidate) {
            foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
                $inlineData = $part['inlineData'] ?? null;
                if (!is_array($inlineData)) {
                    continue;
                }

                $mimeType = (string) ($inlineData['mimeType'] ?? 'image/png');
                $base64 = (string) ($inlineData['data'] ?? '');

                if ($base64 === '') {
                    continue;
                }

                $images[] = [
                    'mime_type' => $mimeType,
                    'base64' => $base64,
                    'data_uri' => 'data:' . $mimeType . ';base64,' . $base64,
                ];
            }
        }

        return $images;
    }

    public function buildImageOptions(array $settings = []): array
    {
        $generationConfig = [];

        if (isset($settings['responseModalities']) && is_array($settings['responseModalities'])) {
            $generationConfig['responseModalities'] = $settings['responseModalities'];
        }

        if (isset($settings['temperature'])) {
            $generationConfig['temperature'] = (float) $settings['temperature'];
        }

        if (isset($settings['topP'])) {
            $generationConfig['topP'] = (float) $settings['topP'];
        }

        if (isset($settings['topK'])) {
            $generationConfig['topK'] = (int) $settings['topK'];
        }

        if (isset($settings['candidateCount'])) {
            $generationConfig['candidateCount'] = (int) $settings['candidateCount'];
        }

        if (isset($settings['maxOutputTokens'])) {
            $generationConfig['maxOutputTokens'] = (int) $settings['maxOutputTokens'];
        }

        if (isset($settings['stopSequences']) && is_array($settings['stopSequences'])) {
            $generationConfig['stopSequences'] = $settings['stopSequences'];
        }

        $imageConfig = [];

        if (isset($settings['aspectRatio'])) {
            $imageConfig['aspectRatio'] = (string) $settings['aspectRatio'];
        }

        if (isset($settings['imageSize'])) {
            $imageSize = mb_strtolower(trim((string) $settings['imageSize']));

            if (! in_array($imageSize, ['', 'default', 'standard', 'standart'], true)) {
                $imageConfig['imageSize'] = (string) $settings['imageSize'];
            }
        }

        if (isset($settings['width'])) {
            $imageConfig['width'] = (int) $settings['width'];
        }

        if (isset($settings['height'])) {
            $imageConfig['height'] = (int) $settings['height'];
        }

        if (isset($settings['outputMimeType'])) {
            $imageConfig['outputMimeType'] = (string) $settings['outputMimeType'];
        }

        if (isset($settings['quality'])) {
            $imageConfig['quality'] = (int) $settings['quality'];
        }

        if (isset($settings['seed'])) {
            $imageConfig['seed'] = (int) $settings['seed'];
        }

        if (isset($settings['personGeneration'])) {
            $imageConfig['personGeneration'] = (string) $settings['personGeneration'];
        }

        if (isset($settings['safetyFilterLevel'])) {
            $imageConfig['safetyFilterLevel'] = (string) $settings['safetyFilterLevel'];
        }

        $options = [];

        if (!empty($generationConfig)) {
            $options['generationConfig'] = $generationConfig;
        }

        if (!empty($imageConfig)) {
            $options['imageConfig'] = $imageConfig;
        }

        if (isset($settings['safetySettings']) && is_array($settings['safetySettings'])) {
            $options['safetySettings'] = $settings['safetySettings'];
        }

        if (isset($settings['systemInstruction'])) {
            $options['systemInstruction'] = $settings['systemInstruction'];
        }

        if (isset($settings['tools']) && is_array($settings['tools'])) {
            $options['tools'] = $settings['tools'];
        }

        if (isset($settings['toolConfig']) && is_array($settings['toolConfig'])) {
            $options['toolConfig'] = $settings['toolConfig'];
        }

        if (isset($settings['raw']) && is_array($settings['raw'])) {
            $options['raw'] = $settings['raw'];
        }

        return $options;
    }

    private function requestGenerateContent(string $model, array $payload): array
    {
        $url = $this->baseUrl . '/' . $this->apiVersion . '/models/' . $model . ':generateContent';
        $sanitizedRequestPayload = $this->sanitizePayloadForLog($payload);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withOptions($this->buildHttpOptions())
                ->timeout(120)
                ->post($url . '?key=' . $this->apiKey, $payload);

            $data = $response->json();

            if (!is_array($data)) {
                $data = [
                    'raw' => $response->body(),
                ];
            }

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'messages' => $response->successful() ? [] : [$data['error']['message'] ?? 'Ошибка Gemini API'],
                'model' => $model,
                'data' => $data,
                'request_payload' => $sanitizedRequestPayload,
            ];
        } catch (\Throwable $exception) {
            Log::error('Gemini API request failed', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 503,
                'messages' => ['Не удалось обратиться к Gemini API'],
                'model' => $model,
                'data' => null,
                'request_payload' => $sanitizedRequestPayload,
            ];
        }
    }

    private function buildHttpOptions(): array
    {
        if ($this->proxy === null || $this->proxy === '') {
            return [];
        }

        return [
            'proxy' => $this->proxy,
        ];
    }

    private function sanitizePayloadForLog(array $payload): array
    {
        $sanitized = $payload;

        $walk = function (&$value, $key) use (&$walk): void {
            if (is_array($value)) {
                foreach ($value as $nestedKey => &$nestedValue) {
                    $walk($nestedValue, $nestedKey);
                }
                return;
            }

            if (is_string($value)) {
                if ($key === 'data') {
                    $value = 'base64_length:' . mb_strlen($value);
                    return;
                }

                if (mb_strlen($value) > 500) {
                    $value = mb_substr($value, 0, 500) . '...';
                }
            }
        };

        foreach ($sanitized as $key => &$value) {
            $walk($value, $key);
        }

        return $sanitized;
    }

    private function buildPayload(array $basePayload, array $options): array
    {
        $payload = $basePayload;

        if (isset($options['systemInstruction'])) {
            $payload['systemInstruction'] = is_string($options['systemInstruction'])
                ? ['parts' => [['text' => $options['systemInstruction']]]]
                : $options['systemInstruction'];
        }

        if (isset($options['generationConfig']) && is_array($options['generationConfig'])) {
            $payload['generationConfig'] = isset($payload['generationConfig'])
                ? array_merge($payload['generationConfig'], $options['generationConfig'])
                : $options['generationConfig'];
        }

        if (isset($options['safetySettings']) && is_array($options['safetySettings'])) {
            $payload['safetySettings'] = $options['safetySettings'];
        }

        if (isset($options['tools']) && is_array($options['tools'])) {
            $payload['tools'] = $options['tools'];
        }

        if (isset($options['toolConfig']) && is_array($options['toolConfig'])) {
            $payload['toolConfig'] = $options['toolConfig'];
        }

        if (isset($options['raw']) && is_array($options['raw'])) {
            $payload = array_replace_recursive($payload, $options['raw']);
        }

        return $payload;
    }

    private function normalizeImage(mixed $image, ?string $mimeType = null): array
    {
        if ($image instanceof UploadedFile) {
            $content = file_get_contents($image->getRealPath());
            if ($content === false) {
                throw new RuntimeException('Не удалось прочитать загруженное изображение');
            }

            $resolvedMimeType = $mimeType ?: ($image->getMimeType() ?: 'image/jpeg');

            return [$resolvedMimeType, base64_encode($content)];
        }

        if (is_string($image) && is_file($image)) {
            $content = file_get_contents($image);
            if ($content === false) {
                throw new RuntimeException('Не удалось прочитать файл изображения');
            }

            $resolvedMimeType = $mimeType ?: (mime_content_type($image) ?: 'image/jpeg');

            return [$resolvedMimeType, base64_encode($content)];
        }

        if (is_string($image) && str_starts_with($image, 'data:')) {
            [$resolvedMimeType, $base64] = $this->parseDataUri($image);
            return [$mimeType ?: $resolvedMimeType, $base64];
        }

        if (is_string($image) && preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $image) === 1) {
            return [$mimeType ?: 'image/jpeg', preg_replace('/\s+/', '', $image) ?? $image];
        }

        throw new RuntimeException('Неподдерживаемый формат изображения для Gemini');
    }

    private function parseDataUri(string $dataUri): array
    {
        if (!preg_match('/^data:([^;]+);base64,(.*)$/', $dataUri, $matches)) {
            throw new RuntimeException('Некорректный data URI изображения');
        }

        return [$matches[1], $matches[2]];
    }
}
