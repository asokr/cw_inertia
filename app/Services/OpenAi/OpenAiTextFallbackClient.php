<?php

namespace App\Services\OpenAi;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiTextFallbackClient
{
    public function generateText(string $prompt, ?string $systemInstruction = null, ?string $imageInput = null): array
    {
        $apiKey = (string) config('services.gpt.key');
        if ($apiKey === '') {
            return [
                'success' => false,
                'status' => 503,
                'messages' => ['Не задан APP_GPT_KEY'],
                'provider' => 'gpt',
                'model' => null,
                'data' => null,
                'request_payload' => null,
            ];
        }

        $model = (string) config('services.gpt.model', 'gpt-4.1');
        $baseUrl = rtrim((string) config('services.gpt.base_url', 'https://api.openai.com'), '/');

        $messages = [];

        if (is_string($systemInstruction) && trim($systemInstruction) !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => trim($systemInstruction),
            ];
        }

        $userContent = trim($prompt);

        $normalizedImageUrl = $this->normalizeImageForMessage($imageInput);
        if ($normalizedImageUrl !== null) {
            $messages[] = [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $userContent,
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $normalizedImageUrl,
                        ],
                    ],
                ],
            ];
        } else {
            $messages[] = [
                'role' => 'user',
                'content' => $userContent,
            ];
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
            'max_tokens' => 4096,
        ];

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

            $response = $request->post($baseUrl . '/v1/chat/completions', $payload);
            $data = $response->json();

            if (! is_array($data)) {
                $data = ['raw' => $response->body()];
            }

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'messages' => $response->successful() ? [] : [(string) (data_get($data, 'error.message') ?: 'Ошибка OpenAI API')],
                'provider' => 'gpt',
                'model' => (string) ($data['model'] ?? $model),
                'data' => $data,
                'request_payload' => $sanitizedRequestPayload,
            ];
        } catch (\Throwable $exception) {
            Log::error('OpenAI text fallback request failed', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 503,
                'messages' => ['Не удалось обратиться к OpenAI API'],
                'provider' => 'gpt',
                'model' => $model,
                'data' => null,
                'request_payload' => $sanitizedRequestPayload,
            ];
        }
    }

    public function extractText(array $response): string
    {
        return trim((string) data_get($response, 'choices.0.message.content', ''));
    }

    private function normalizeImageForMessage(?string $imageInput): ?string
    {
        if (! is_string($imageInput)) {
            return null;
        }

        $trimmed = trim($imageInput);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, 'data:')) {
            return $trimmed;
        }

        if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $trimmed) === 1) {
            $base64 = preg_replace('/\s+/', '', $trimmed) ?? '';
            if ($base64 !== '') {
                return 'data:image/jpeg;base64,' . $base64;
            }
        }

        return null;
    }

    private function sanitizePayloadForLog(array $payload): array
    {
        $sanitized = $payload;

        $walker = function (&$value, $key) use (&$walker): void {
            if (is_array($value)) {
                foreach ($value as $nestedKey => &$nestedValue) {
                    $walker($nestedValue, $nestedKey);
                }

                return;
            }

            if (! is_string($value)) {
                return;
            }

            if ($key === 'url' && str_starts_with($value, 'data:')) {
                $value = 'data_uri_length:' . mb_strlen($value);
                return;
            }

            if (mb_strlen($value) > 500) {
                $value = mb_substr($value, 0, 500) . '...';
            }
        };

        foreach ($sanitized as $key => &$value) {
            $walker($value, $key);
        }

        return $sanitized;
    }
}
