<?php

namespace App\Services\Wb;

use App\Http\Traits\GuzzleTrait;
use Illuminate\Support\Facades\Log;

/**
 * Content API media helpers for product card photos.
 *
 * @see https://dev.wildberries.ru/docs/openapi/work-with-products#tag/mediaFiles
 */
class WbContentMediaClient
{
    use GuzzleTrait;

    public const BASE_URL = 'https://content-api.wildberries.ru';

    /**
     * POST /content/v3/media/file — upload binary as card media slot.
     *
     * @return array{success: bool, code: int, data: mixed, message?: string}
     */
    public function uploadMediaFile(
        string $apiKey,
        int $nmId,
        int $photoNumber,
        string $binary,
        string $filename = 'photo.jpg',
        string $mime = 'image/jpeg',
    ): array {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return [
                'success' => false,
                'code' => 0,
                'data' => null,
                'message' => 'Пустой API-ключ кабинета Wildberries',
            ];
        }

        if ($nmId <= 0) {
            return [
                'success' => false,
                'code' => 400,
                'data' => null,
                'message' => 'Некорректный nmID для загрузки фото',
            ];
        }

        if ($photoNumber < 1 || $photoNumber > 30) {
            return [
                'success' => false,
                'code' => 400,
                'data' => null,
                'message' => 'Номер фотографии должен быть от 1 до 30',
            ];
        }

        if ($binary === '') {
            return [
                'success' => false,
                'code' => 400,
                'data' => null,
                'message' => 'Пустой файл фотографии',
            ];
        }

        $url = self::BASE_URL.'/content/v3/media/file';
        $multipart = [
            [
                'name' => 'uploadfile',
                'contents' => $binary,
                'filename' => $filename !== '' ? $filename : 'photo.jpg',
                'headers' => [
                    'Content-Type' => $mime !== '' ? $mime : 'image/jpeg',
                ],
            ],
        ];

        $extraHeaders = [
            'X-Nm-Id' => (string) $nmId,
            'X-Photo-Number' => (string) $photoNumber,
        ];

        $raw = $this->postMultipartRequest(
            $url,
            $apiKey,
            $multipart,
            $extraHeaders,
            'contentMediaFile',
        );

        return $this->normalizeResponse($raw);
    }

    /**
     * @param  array{headers?: array, response?: mixed, code?: int}  $raw
     * @return array{success: bool, code: int, data: mixed, message?: string}
     */
    private function normalizeResponse(array $raw): array
    {
        $code = (int) ($raw['code'] ?? 0);
        $body = $raw['response'] ?? null;

        if ($code === 204 || ($code >= 200 && $code < 300 && ($body === '' || $body === null))) {
            return ['success' => true, 'code' => $code, 'data' => null];
        }

        $decoded = null;
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $decoded = null;
            }
        }

        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'code' => $code, 'data' => $decoded ?? $body];
        }

        $message = 'Ошибка Content API при загрузке фотографии';
        if (is_array($decoded)) {
            foreach (['errorText', 'detail', 'message', 'title', 'error'] as $key) {
                $value = $decoded[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $message = trim($value);
                    break;
                }
            }
        } elseif (is_string($body) && trim($body) !== '' && strlen($body) < 300) {
            $message = trim($body);
        }

        if ($code === 401 || $code === 403) {
            $message = 'Нет доступа к Content API. Проверьте API-ключ и категорию «Контент».';
        } elseif ($code === 429) {
            $message = 'Превышен лимит запросов Content API. Повторите позже.';
        }

        Log::warning('[WbContentMediaClient] upload failed', [
            'code' => $code,
            'message' => $message,
        ]);

        return [
            'success' => false,
            'code' => $code,
            'data' => $decoded ?? $body,
            'message' => $message,
        ];
    }
}
