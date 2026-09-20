<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AiMediaStorageService
{
    /**
     * @return array{path:string,signed_url:string,url_preview:string,mime_type:string,size:int}
     */
    public function storeImageAndGetSignedUrl(string $imageInput, ?int $userId = null, ?string $prefixOverride = null): array
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
        $prefix = trim((string) ($prefixOverride ?? config('services.ai_media.image_prefix', 'ai/source-images')), '/');
        $datePath = now()->format('Y');
        $userPart = 'user-' . $userId . '/';
        $path = $prefix . '/' . $userPart . $datePath . '/' . Str::uuid() . '.' . $extension;

        $diskName = (string) config('services.ai_media.disk', 'private');
        $putResult = $this->safePut($diskName, $path, $binary, [
            'visibility' => 'private',
            'ContentType' => $mimeType,
        ]);

        if (! $putResult) {
            throw new RuntimeException('Не удалось сохранить изображение в хранилище');
        }

        $mediaUrl = $this->buildAccessibleMediaUrl($path);

        return [
            'path' => $path,
            'signed_url' => $mediaUrl,
            'url_preview' => $mediaUrl,
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

        try {
            $response = Http::timeout(120)
                ->withOptions($this->providerDownloadHttpOptions())
                ->get($trimmedUrl);
        } catch (Throwable $exception) {
            Log::warning('AI media video download failed', [
                'url' => $trimmedUrl,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Не удалось скачать сгенерированное видео по ссылке провайдера');
        }

        if (! $response->successful()) {
            Log::warning('AI media video download returned non-success', [
                'url' => $trimmedUrl,
                'status' => $response->status(),
            ]);

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
        $mimeType = $this->detectVideoMime(
            $binary,
            $contentType !== '' ? explode(';', $contentType)[0] : '',
            $trimmedUrl,
        );
        if ($mimeType === null) {
            throw new RuntimeException('Неподдерживаемый формат видео');
        }

        $extension = $this->resolveVideoExtensionByMime($mimeType);
        $prefix = trim((string) config('services.ai_media.video_prefix', 'ai/generated-videos'), '/');
        $datePath = now()->format('Y');
        $userPart = 'user-' . $userId . '/';
        $path = $prefix . '/' . $userPart . $datePath . '/' . Str::uuid() . '.' . $extension;

        $diskName = (string) config('services.ai_media.disk', 'private');
        $putResult = $this->safePut($diskName, $path, $binary, [
            'visibility' => 'private',
            'ContentType' => $mimeType,
        ]);

        if (! $putResult) {
            throw new RuntimeException('Не удалось сохранить видео в хранилище');
        }

        $mediaUrl = $this->buildAccessibleMediaUrl($path);

        return [
            'path' => $path,
            'signed_url' => $mediaUrl,
            'url_preview' => $mediaUrl,
            'mime_type' => $mimeType,
            'size' => $size,
        ];
    }

    /**
     * Сохраняет исходный ролик для редактирования.
     *
     * @return array{path:string,signed_url:string,url_preview:string,mime_type:string,size:int}
     */
    public function storeSourceVideoBinary(string $binary, int $userId): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Не указан пользователь для сохранения медиа');
        }

        $size = strlen($binary);
        if ($size <= 0) {
            throw new RuntimeException('Ролик не передан');
        }

        $maxBytes = max(1, (int) config('services.ai_media.max_source_video_bytes', 25 * 1024 * 1024));
        if ($size > $maxBytes) {
            throw new RuntimeException('Файл слишком большой. Загрузите ролик до 25 МБ.');
        }

        $prefix = trim((string) config('services.ai_media.source_video_prefix', 'ai/source-videos'), '/');
        $path = $prefix.'/user-'.$userId.'/'.now()->format('Y').'/'.Str::uuid().'.mp4';
        $diskName = (string) config('services.ai_media.disk', 'private');
        $putResult = $this->safePut($diskName, $path, $binary, [
            'visibility' => 'private',
            'ContentType' => 'video/mp4',
        ]);

        if (! $putResult) {
            throw new RuntimeException('Не удалось сохранить ролик');
        }

        $mediaUrl = $this->buildAccessibleMediaUrl($path);

        return [
            'path' => $path,
            'signed_url' => $mediaUrl,
            'url_preview' => $mediaUrl,
            'mime_type' => 'video/mp4',
            'size' => $size,
        ];
    }

    /**
     * Читает уже сохранённый ролик пользователя без повторной записи.
     *
     * @return array{0:string,1:array{path:string,signed_url:string,url_preview:string,mime_type:string,size:int}}
     */
    public function resolveOwnedSourceVideo(string $input, int $userId): array
    {
        if (! $this->panelMediaBelongsToUser($input, $userId)) {
            throw new RuntimeException('Ролик не найден');
        }

        [$binary, $mimeType] = $this->resolveVideoBinaryAndMime($input);
        $path = (string) $this->resolveStoragePathFromMediaUrl($input);
        $size = strlen($binary);
        $maxBytes = max(1, (int) config('services.ai_media.max_source_video_bytes', 25 * 1024 * 1024));
        if ($size > $maxBytes) {
            throw new RuntimeException('Файл слишком большой. Загрузите ролик до 25 МБ.');
        }

        $mediaUrl = $this->buildAccessibleMediaUrl($path);

        return [
            $binary,
            [
                'path' => $path,
                'signed_url' => $mediaUrl,
                'url_preview' => $mediaUrl,
                'mime_type' => $mimeType,
                'size' => $size,
            ],
        ];
    }

    /**
     * @return array{0:string,1:string} [binary, mimeType]
     */
    public function resolveVideoBinaryAndMime(string $videoInput): array
    {
        $trimmed = trim($videoInput);
        if ($trimmed === '') {
            throw new RuntimeException('Ролик не передан');
        }

        $storagePath = $this->resolveStoragePathFromMediaUrl($trimmed);
        if ($storagePath !== null) {
            return $this->readVideoBinaryFromStoragePath($storagePath);
        }

        if (str_starts_with($trimmed, 'data:')) {
            if (! preg_match('/^data:(?<mime>[-\w.+\/]+);base64,(?<data>.+)$/s', $trimmed, $matches)) {
                throw new RuntimeException('Этот ролик не подходит. Загрузите обычный MP4-файл.');
            }

            $mimeType = $this->normalizeVideoMime((string) ($matches['mime'] ?? ''));
            if ($mimeType !== 'video/mp4') {
                throw new RuntimeException('Этот ролик не подходит. Загрузите обычный MP4-файл.');
            }

            $binary = base64_decode(preg_replace('/\s+/', '', (string) ($matches['data'] ?? '')) ?: '', true);
            if ($binary === false || $binary === '') {
                throw new RuntimeException('Этот ролик не подходит. Загрузите обычный MP4-файл.');
            }

            return [$binary, $mimeType];
        }

        throw new RuntimeException('Этот ролик не подходит. Загрузите обычный MP4-файл.');
    }

    public function buildAccessibleMediaUrl(string $path): string
    {
        $normalizedPath = $this->normalizeStoragePath($path);

        return $normalizedPath !== ''
            ? $this->buildPanelMediaUrl($normalizedPath)
            : '';
    }

    /**
     * @return array{disk:Filesystem,disk_name:string}|null
     */
    public function resolveDiskForPath(string $path): ?array
    {
        $normalizedPath = $this->normalizeStoragePath($path);
        if ($normalizedPath === '') {
            return null;
        }

        $diskName = (string) config('services.ai_media.disk', 'private');

        try {
            $disk = Storage::disk($diskName);
            foreach ($this->storagePathCandidates($normalizedPath) as $candidate) {
                if ($disk->exists($candidate)) {
                    return [
                        'disk' => $disk,
                        'disk_name' => $diskName,
                        'path' => $candidate,
                    ];
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('AI media storage disk lookup failed', [
                'disk' => $diskName,
                'path' => $normalizedPath,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    public function buildPanelMediaUrl(string $path): string
    {
        $normalized = trim($path, '/');
        if (str_starts_with($normalized, 'ai/')) {
            $normalized = substr($normalized, 3);
        }

        $segments = array_filter(explode('/', $normalized), static fn (string $segment): bool => $segment !== '');

        return '/panel/ai/media/' . implode('/', array_map('rawurlencode', $segments));
    }

    public function resolvePanelMediaUrl(?string $url = null, ?string $path = null): ?string
    {
        $storagePath = trim((string) $path);
        if ($storagePath !== '') {
            return $this->buildAccessibleMediaUrl($storagePath);
        }

        $rawUrl = trim((string) $url);
        if ($rawUrl === '') {
            return null;
        }

        if (str_starts_with($rawUrl, '/panel/ai/media/')) {
            $storagePathFromUrl = $this->resolveStoragePathFromMediaUrl($rawUrl);

            return $storagePathFromUrl !== null
                ? $this->buildAccessibleMediaUrl($storagePathFromUrl)
                : $rawUrl;
        }

        if (str_starts_with($rawUrl, '/api/subscriber/ai/media/')) {
            $relative = substr($rawUrl, strlen('/api/subscriber/ai/media/'));
            $decodedSegments = array_map(
                static fn (string $segment): string => rawurldecode($segment),
                array_filter(explode('/', $relative), static fn (string $segment): bool => $segment !== ''),
            );

            $storagePath = $this->normalizeStoragePath(implode('/', $decodedSegments));

            return $storagePath !== ''
                ? $this->buildAccessibleMediaUrl($storagePath)
                : null;
        }

        if (str_starts_with($rawUrl, 'http://') || str_starts_with($rawUrl, 'https://')) {
            $parsedPath = (string) (parse_url($rawUrl, PHP_URL_PATH) ?? '');
            $resolved = $this->resolvePanelMediaUrl($parsedPath);

            if ($resolved === null || $resolved === '') {
                return $rawUrl;
            }

            return $resolved;
        }

        if ($this->isStorageRelativeMediaPath($rawUrl)) {
            return $this->buildAccessibleMediaUrl($rawUrl);
        }

        return $rawUrl;
    }

    private function isStorageRelativeMediaPath(string $path): bool
    {
        $normalized = ltrim($path, '/');
        if (str_starts_with($normalized, 'ai/')) {
            $normalized = substr($normalized, 3);
        }

        return str_starts_with($normalized, 'generated-videos/')
            || str_starts_with($normalized, 'source-images/')
            || str_starts_with($normalized, 'source-videos/');
    }

    /**
     * @return array{0:string,1:string}
     */
    public function resolveImageInlineData(string $imageInput): array
    {
        [$binary, $mimeType] = $this->resolveImageBinaryAndMime($imageInput);

        return [$mimeType, base64_encode($binary)];
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

        $storagePath = $this->resolveStoragePathFromMediaUrl($trimmed);
        if ($storagePath !== null) {
            return $this->readBinaryFromStoragePath($storagePath);
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

    public function isStoredMediaReference(string $imageInput): bool
    {
        $trimmed = trim($imageInput);
        if ($trimmed === '') {
            return false;
        }

        if ($this->isPanelMediaInput($trimmed)) {
            return true;
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return true;
        }

        return false;
    }

    public function isPanelMediaInput(string $imageInput): bool
    {
        return $this->resolveStoragePathFromMediaUrl($imageInput) !== null;
    }

    public function isStreamableVideoPath(string $path): bool
    {
        $normalized = $this->normalizeStoragePath($path);
        $videoPrefix = trim((string) config('services.ai_media.video_prefix', 'ai/generated-videos'), '/');
        $sourceVideoPrefix = trim((string) config('services.ai_media.source_video_prefix', 'ai/source-videos'), '/');

        return str_starts_with($normalized, $videoPrefix.'/')
            || str_starts_with($normalized, $sourceVideoPrefix.'/');
    }

    public function isAllowedMediaPath(string $path, ?string $userPrefix = null): bool
    {
        $normalized = $this->normalizeStoragePath($path);
        $prefixes = [
            trim((string) config('services.ai_media.image_prefix', 'ai/source-images'), '/'),
            trim((string) config('services.ai_media.video_prefix', 'ai/generated-videos'), '/'),
            trim((string) config('services.ai_media.source_video_prefix', 'ai/source-videos'), '/'),
        ];

        foreach ($prefixes as $prefix) {
            $needle = $userPrefix !== null && $userPrefix !== ''
                ? $prefix.'/'.$userPrefix
                : $prefix.'/';

            if (str_starts_with($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function panelMediaBelongsToUser(string $imageInput, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $path = $this->resolveStoragePathFromMediaUrl($imageInput);
        if ($path === null) {
            return false;
        }

        return str_contains('/'.$path.'/', '/user-'.$userId.'/');
    }

    private function resolveStoragePathFromMediaUrl(string $url): ?string
    {
        $trimmed = trim($url);
        if (str_contains($trimmed, '?')) {
            $trimmed = strstr($trimmed, '?', true) ?: $trimmed;
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            $parsedPath = (string) (parse_url($trimmed, PHP_URL_PATH) ?? '');
            if ($parsedPath !== '') {
                $trimmed = $parsedPath;
            }
        }

        if (str_starts_with($trimmed, '/panel/ai/media/')) {
            $relative = substr($trimmed, strlen('/panel/ai/media/'));
        } elseif (str_starts_with($trimmed, '/api/subscriber/ai/media/')) {
            $relative = substr($trimmed, strlen('/api/subscriber/ai/media/'));
        } else {
            return null;
        }

        $segments = array_map(
            static fn (string $segment): string => rawurldecode($segment),
            array_filter(explode('/', $relative), static fn (string $segment): bool => $segment !== ''),
        );

        if ($segments === []) {
            return null;
        }

        $normalizedPath = implode('/', $segments);

        return $this->normalizeStoragePath($normalizedPath);
    }

    public function normalizeStoragePath(string $path): string
    {
        $normalizedPath = trim(str_replace('\\', '/', $path), '/');
        if ($normalizedPath === '') {
            return '';
        }

        if (
            ! str_starts_with($normalizedPath, 'ai/')
            && (
                str_starts_with($normalizedPath, 'source-images/')
                || str_starts_with($normalizedPath, 'generated-videos/')
                || str_starts_with($normalizedPath, 'source-videos/')
            )
        ) {
            $normalizedPath = 'ai/' . $normalizedPath;
        }

        return $normalizedPath;
    }

    /**
     * @return array<int, string>
     */
    private function storagePathCandidates(string $path): array
    {
        $normalized = $this->normalizeStoragePath($path);
        $candidates = [$normalized];

        if (str_starts_with($normalized, 'ai/')) {
            $candidates[] = substr($normalized, 3);
        } else {
            $candidates[] = 'ai/' . $normalized;
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * @return array{0:string,1:string}
     */
    private function readBinaryFromStoragePath(string $path): array
    {
        $resolvedDisk = $this->resolveDiskForPath($path);
        if ($resolvedDisk === null) {
            throw new RuntimeException('Файл изображения не найден');
        }

        $disk = $resolvedDisk['disk'];
        $resolvedPath = (string) ($resolvedDisk['path'] ?? $path);

        $binary = $disk->get($resolvedPath);
        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('Изображение пустое');
        }

        $mimeType = $this->normalizeMime((string) ($disk->mimeType($resolvedPath) ?: ''));
        if ($mimeType === null) {
            $mimeType = match (mb_strtolower((string) pathinfo($resolvedPath, PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };
        }

        return [$binary, $mimeType];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function readVideoBinaryFromStoragePath(string $path): array
    {
        $resolvedDisk = $this->resolveDiskForPath($path);
        if ($resolvedDisk === null) {
            throw new RuntimeException('Ролик не найден');
        }

        $disk = $resolvedDisk['disk'];
        $resolvedPath = (string) ($resolvedDisk['path'] ?? $path);

        $binary = $disk->get($resolvedPath);
        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('Ролик не найден');
        }

        $mimeType = $this->normalizeVideoMime((string) ($disk->mimeType($resolvedPath) ?: ''));
        if ($mimeType === null) {
            $mimeType = match (mb_strtolower((string) pathinfo($resolvedPath, PATHINFO_EXTENSION))) {
                'mp4' => 'video/mp4',
                default => null,
            };
        }

        if ($mimeType !== 'video/mp4') {
            throw new RuntimeException('Этот ролик не подходит. Загрузите обычный MP4-файл.');
        }

        return [$binary, $mimeType];
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

    /**
     * Те же TLS/proxy, что у Grok-клиента: локально GROK_HTTP_VERIFY=false,
     * иначе скачивание с vidgen.x.ai падает с cURL 60.
     *
     * @return array<string, mixed>
     */
    private function providerDownloadHttpOptions(): array
    {
        $options = [];
        $proxy = config('services.proxy');

        if ($proxy !== null && $proxy !== '') {
            $options['proxy'] = $proxy;
        }

        if (! (bool) config('services.grok.http_verify', true)) {
            $options['verify'] = false;
        }

        return $options;
    }

    private function detectVideoMime(string $binary, string $contentType, string $url): ?string
    {
        $normalized = $this->normalizeVideoMime($contentType);
        if ($normalized !== null) {
            return $normalized;
        }

        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if (str_ends_with($path, '.mp4')) {
            return 'video/mp4';
        }

        if (str_ends_with($path, '.webm')) {
            return 'video/webm';
        }

        if (strlen($binary) >= 8 && substr($binary, 4, 4) === 'ftyp') {
            return 'video/mp4';
        }

        return $this->normalizeVideoMime($contentType);
    }

    private function normalizeVideoMime(string $mimeType): ?string
    {
        return match (mb_strtolower(trim($mimeType))) {
            'video/mp4' => 'video/mp4',
            'video/webm' => 'video/webm',
            'application/octet-stream', 'binary/octet-stream' => null,
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

    public function deleteFileByPath(?string $path): void
    {
        $normalizedPath = trim((string) $path);
        if ($normalizedPath === '') {
            return;
        }

        $diskName = (string) config('services.ai_media.disk', 'private');

        try {
            if (Storage::disk($diskName)->exists($normalizedPath)) {
                Storage::disk($diskName)->delete($normalizedPath);
            }
        } catch (\Throwable $exception) {
            Log::warning('AI media storage delete failed', [
                'disk' => $diskName,
                'path' => $normalizedPath,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function deleteTaskMedia(\App\Models\AiVideoGenerationTask $task): void
    {
        $resultVideo = is_array($task->result_video) ? $task->result_video : [];
        $this->deleteFileByPath($resultVideo['path'] ?? null);

        $this->deleteSourceImages(is_array($task->source_images) ? $task->source_images : []);
    }

    public function deleteImageTaskMedia(\App\Models\AiImageGenerationTask $task): void
    {
        $resultImages = is_array($task->result_images) ? $task->result_images : [];
        foreach ($resultImages as $image) {
            if (! is_array($image)) {
                continue;
            }

            $this->deleteFileByPath($image['path'] ?? null);
        }

        $this->deleteSourceImages(is_array($task->source_images) ? $task->source_images : []);
    }

    /**
     * @param  array<int, mixed>  $sourceImages
     */
    private function deleteSourceImages(array $sourceImages): void
    {
        foreach ($sourceImages as $image) {
            if (! is_array($image)) {
                continue;
            }

            $this->deleteFileByPath($image['path'] ?? null);
        }
    }

    private function safePut(string $diskName, string $path, string $binary, array $options): bool
    {
        try {
            $saved = (bool) Storage::disk($diskName)->put($path, $binary, $options);
            if ($saved) {
                $this->ensureLocalFileReadable($diskName, $path);
            }

            return $saved;
        } catch (\Throwable $exception) {
            Log::warning('AI media storage put failed', [
                'disk' => $diskName,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function ensureLocalFileReadable(string $diskName, string $path): void
    {
        if ((string) config("filesystems.disks.{$diskName}.driver") !== 'local') {
            return;
        }

        try {
            $fullPath = Storage::disk($diskName)->path($path);
            if (! is_string($fullPath) || $fullPath === '') {
                return;
            }

            if (is_file($fullPath)) {
                @chmod($fullPath, 0644);
            }

            $directory = dirname($fullPath);
            $root = rtrim((string) Storage::disk($diskName)->path(''), DIRECTORY_SEPARATOR);
            while ($directory !== '' && $directory !== '.' && str_starts_with($directory, $root)) {
                if (is_dir($directory)) {
                    @chmod($directory, 0755);
                }

                $parent = dirname($directory);
                if ($parent === $directory) {
                    break;
                }

                $directory = $parent;
            }
        } catch (\Throwable $exception) {
            Log::warning('AI media storage chmod failed', [
                'disk' => $diskName,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}