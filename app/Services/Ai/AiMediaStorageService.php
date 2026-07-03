<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AiMediaStorageService
{
    /**
     * @return array{path:string,signed_url:string,url_preview:string,mime_type:string,size:int}
     */
    public function storeImageAndGetSignedUrl(string $imageInput, ?int $userId = null): array
    {
        [$binary, $mimeType] = $this->resolveImageBinaryAndMime($imageInput);

        if (! $userId || $userId <= 0) {
            throw new RuntimeException('Не указан пользователь для сохранения медиа');
        }

        $maxBytes = max(1, (int) config('services.ai_media.max_image_bytes', 10 * 1024 * 1024));
        $size = strlen($binary);
        if ($size <= 0) {
            throw new RuntimeException('Изображение пустое');
        }

        if ($size > $maxBytes) {
            throw new RuntimeException('Изображение слишком большое');
        }

        $extension = $this->resolveExtensionByMime($mimeType);
        $prefix = trim((string) config('services.ai_media.image_prefix', 'ai/source-images'), '/');
        $datePath = now()->format('Y');
        $userPart = 'user-' . $userId . '/';
        $path = $prefix . '/' . $userPart . $datePath . '/' . Str::uuid() . '.' . $extension;

        $diskName = (string) config('services.ai_media.disk', 'private');
        $putResult = $this->putPrivateFileWithFallback($diskName, $path, $binary, $mimeType);

        if (! $putResult) {
            throw new RuntimeException('Не удалось сохранить изображение в хранилище');
        }

        $internalUrl = $this->buildSubscriberMediaUrl($path);

        return [
            'path' => $path,
            'signed_url' => $internalUrl,
            'url_preview' => $internalUrl,
            'mime_type' => $mimeType,
            'size' => $size,
        ];
    }

    /**
     * @return array{path:string,signed_url:string,url_preview:string,mime_type:string,size:int}
     */
    public function storeVideoByUrlAndGetSignedUrl(string $videoUrl, ?int $userId = null): array
    {
        $trimmedUrl = trim($videoUrl);
        if ($trimmedUrl === '' || (! str_starts_with($trimmedUrl, 'http://') && ! str_starts_with($trimmedUrl, 'https://'))) {
            throw new RuntimeException('Некорректная ссылка на видео');
        }

        if (! $userId || $userId <= 0) {
            throw new RuntimeException('Не указан пользователь для сохранения медиа');
        }

        $response = Http::timeout(120)->get($trimmedUrl);
        if (! $response->successful()) {
            throw new RuntimeException('Не удалось скачать сгенерированное видео по ссылке провайдера');
        }

        $binary = $response->body();
        $size = strlen($binary);
        if ($size <= 0) {
            throw new RuntimeException('Сгенерированное видео пустое');
        }

        $maxBytes = max(1, (int) config('services.ai_media.max_video_bytes', 100 * 1024 * 1024));
        if ($size > $maxBytes) {
            throw new RuntimeException('Сгенерированное видео слишком большое');
        }

        $contentType = strtolower(trim((string) $response->header('Content-Type', '')));
        $mimeType = $this->normalizeVideoMime($contentType !== '' ? explode(';', $contentType)[0] : '');
        if ($mimeType === null) {
            throw new RuntimeException('Неподдерживаемый формат видео');
        }

        $extension = $this->resolveVideoExtensionByMime($mimeType);
        $prefix = trim((string) config('services.ai_media.video_prefix', 'ai/generated-videos'), '/');
        $datePath = now()->format('Y');
        $userPart = 'user-' . $userId . '/';
        $path = $prefix . '/' . $userPart . $datePath . '/' . Str::uuid() . '.' . $extension;

        $diskName = (string) config('services.ai_media.disk', 'private');
        $putResult = $this->putPrivateFileWithFallback($diskName, $path, $binary, $mimeType);

        if (! $putResult) {
            throw new RuntimeException('Не удалось сохранить видео в хранилище');
        }

        $internalUrl = $this->buildSubscriberMediaUrl($path);

        return [
            'path' => $path,
            'signed_url' => $internalUrl,
            'url_preview' => $internalUrl,
            'mime_type' => $mimeType,
            'size' => $size,
        ];
    }

    private function buildSubscriberMediaUrl(string $path): string
    {
        return '/api/subscriber/ai/media/' . $this->encodePathForRoute($path);
    }

    private function encodePathForRoute(string $path): string
    {
        $segments = array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => $segment !== '');

        return implode('/', array_map(static fn(string $segment): string => rawurlencode($segment), $segments));
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveImageBinaryAndMime(string $imageInput): array
    {
        $trimmed = trim($imageInput);
        if ($trimmed === '') {
            throw new RuntimeException('Изображение не передано');
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            $response = Http::timeout(20)->get($trimmed);
            if (! $response->successful()) {
                throw new RuntimeException('Не удалось скачать изображение по ссылке');
            }

            $contentType = strtolower(trim((string) $response->header('Content-Type', '')));
            $mimeType = $this->normalizeMime($contentType !== '' ? explode(';', $contentType)[0] : '');
            if ($mimeType === null) {
                throw new RuntimeException('Неподдерживаемый формат изображения');
            }

            return [$response->body(), $mimeType];
        }

        if (str_starts_with($trimmed, 'data:')) {
            if (! preg_match('/^data:(?<mime>[-\w.+\/]+);base64,(?<data>.+)$/s', $trimmed, $matches)) {
                throw new RuntimeException('Некорректный data URI изображения');
            }

            $mimeType = $this->normalizeMime((string) ($matches['mime'] ?? ''));
            if ($mimeType === null) {
                throw new RuntimeException('Неподдерживаемый формат изображения');
            }

            $binary = base64_decode(preg_replace('/\s+/', '', (string) ($matches['data'] ?? '')) ?: '', true);
            if ($binary === false) {
                throw new RuntimeException('Некорректный base64 изображения');
            }

            return [$binary, $mimeType];
        }

        if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $trimmed) === 1) {
            $binary = base64_decode(preg_replace('/\s+/', '', $trimmed) ?: '', true);
            if ($binary === false) {
                throw new RuntimeException('Некорректный base64 изображения');
            }

            return [$binary, 'image/jpeg'];
        }

        throw new RuntimeException('Неподдерживаемый формат изображения');
    }

    private function normalizeMime(string $mimeType): ?string
    {
        return match (mb_strtolower(trim($mimeType))) {
            'image/jpeg', 'image/jpg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
            default => null,
        };
    }

    private function resolveExtensionByMime(string $mimeType): string
    {
        return match ($mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    private function normalizeVideoMime(string $mimeType): ?string
    {
        return match (mb_strtolower(trim($mimeType))) {
            'video/mp4' => 'video/mp4',
            'video/webm' => 'video/webm',
            default => null,
        };
    }

    private function resolveVideoExtensionByMime(string $mimeType): string
    {
        return match ($mimeType) {
            'video/webm' => 'webm',
            default => 'mp4',
        };
    }

    private function putPrivateFileWithFallback(string $diskName, string $path, string $binary, string $mimeType): bool
    {
        $options = [
            'visibility' => 'private',
            'ContentType' => $mimeType,
        ];

        $primaryPutResult = $this->safePut($diskName, $path, $binary, $options);
        if ($primaryPutResult) {
            return true;
        }

        if ($diskName === 'private') {
            return false;
        }

        $fallbackPutResult = $this->safePut('private', $path, $binary, $options);
        if ($fallbackPutResult) {
            Log::warning('AI media storage fallback to private disk used', [
                'primary_disk' => $diskName,
                'fallback_disk' => 'private',
                'path' => $path,
            ]);

            return true;
        }

        return false;
    }

    private function safePut(string $diskName, string $path, string $binary, array $options): bool
    {
        try {
            return (bool) Storage::disk($diskName)->put($path, $binary, $options);
        } catch (\Throwable $exception) {
            Log::warning('AI media storage put failed', [
                'disk' => $diskName,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
