<?php

namespace App\Http\Controllers\Web\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiMediaStorageService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __construct(private readonly AiMediaStorageService $aiMediaStorageService)
    {
    }

    public function show(string $path): Response|StreamedResponse
    {
        $normalizedPath = $this->normalizePath($path);
        if ($normalizedPath === null) {
            abort(404);
        }

        $resolvedDisk = $this->aiMediaStorageService->resolveDiskForPath($normalizedPath);
        if ($resolvedDisk === null) {
            abort(404);
        }

        $disk = $resolvedDisk['disk'];
        $resolvedPath = (string) ($resolvedDisk['path'] ?? $normalizedPath);

        $stream = $disk->readStream($resolvedPath);
        if (! is_resource($stream)) {
            abort(404);
        }

        $mimeType = (string) ($disk->mimeType($resolvedPath) ?: 'application/octet-stream');
        $size = $disk->size($resolvedPath);
        $filename = basename($resolvedPath);

        $headers = [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ];

        if (is_int($size) && $size >= 0) {
            $headers['Content-Length'] = (string) $size;
        }

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }

    private function normalizePath(string $path): ?string
    {
        $decodedSegments = array_map(
            static fn (string $segment): string => rawurldecode($segment),
            explode('/', $path)
        );

        $segments = [];
        foreach ($decodedSegments as $segment) {
            $trimmed = trim($segment);
            if ($trimmed === '') {
                continue;
            }

            if ($trimmed === '.' || $trimmed === '..') {
                return null;
            }

            $segments[] = $trimmed;
        }

        if ($segments === []) {
            return null;
        }

        $normalizedPath = $this->aiMediaStorageService->normalizeStoragePath(implode('/', $segments));
        if ($normalizedPath === '') {
            return null;
        }

        $imagePrefix = trim((string) config('services.ai_media.image_prefix', 'ai/source-images'), '/');
        $videoPrefix = trim((string) config('services.ai_media.video_prefix', 'ai/generated-videos'), '/');

        $isImagePath = str_starts_with($normalizedPath, $imagePrefix . '/');
        $isVideoPath = str_starts_with($normalizedPath, $videoPrefix . '/');

        if (! $isImagePath && ! $isVideoPath) {
            return null;
        }

        return $normalizedPath;
    }
}