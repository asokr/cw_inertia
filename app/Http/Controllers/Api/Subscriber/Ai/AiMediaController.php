<?php

namespace App\Http\Controllers\Api\Subscriber\Ai;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiMediaController extends Controller
{
    public function show(string $path): Response|StreamedResponse
    {
        $userId = (int) (auth()->id() ?? 0);
        if ($userId <= 0) {
            abort(403);
        }

        $normalizedPath = $this->normalizePath($path);
        if ($normalizedPath === null) {
            abort(404);
        }

        $diskName = (string) config('services.ai_media.disk', 'private');
        $disk = Storage::disk($diskName);

        if (! $disk->exists($normalizedPath)) {
            abort(404);
        }

        $stream = $disk->readStream($normalizedPath);
        if (! is_resource($stream)) {
            abort(404);
        }

        $mimeType = (string) ($disk->mimeType($normalizedPath) ?: 'application/octet-stream');
        $size = $disk->size($normalizedPath);
        $filename = basename($normalizedPath);

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
            static fn(string $segment): string => rawurldecode($segment),
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

        $normalizedPath = implode('/', $segments);

        if (
            ! str_starts_with($normalizedPath, 'ai/')
            && (
                str_starts_with($normalizedPath, 'source-images/')
                || str_starts_with($normalizedPath, 'generated-videos/')
            )
        ) {
            $normalizedPath = 'ai/' . $normalizedPath;
        }

        $imagePrefix = trim((string) config('services.ai_media.image_prefix', 'ai/source-images'), '/');
        $videoPrefix = trim((string) config('services.ai_media.video_prefix', 'ai/generated-videos'), '/');
        $userPrefix = 'user-' . (int) auth()->id() . '/';

        $isImagePath = str_starts_with($normalizedPath, $imagePrefix . '/' . $userPrefix);
        $isVideoPath = str_starts_with($normalizedPath, $videoPrefix . '/' . $userPrefix);

        if (! $isImagePath && ! $isVideoPath) {
            return null;
        }

        return $normalizedPath;
    }
}
