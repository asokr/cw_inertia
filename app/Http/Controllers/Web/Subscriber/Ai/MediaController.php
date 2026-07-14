<?php

namespace App\Http\Controllers\Web\Subscriber\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiMediaStorageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __construct(private readonly AiMediaStorageService $aiMediaStorageService)
    {
    }

    public function show(Request $request, string $path): Response|StreamedResponse
    {
        $userId = (int) (auth()->id() ?? 0);
        if ($userId <= 0) {
            abort(403);
        }

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

        $mimeType = (string) ($disk->mimeType($resolvedPath) ?: 'application/octet-stream');
        $size = (int) $disk->size($resolvedPath);
        $filename = basename($resolvedPath);

        if ($this->isVideoPath($resolvedPath)) {
            return $this->respondWithStream($request, $disk, $resolvedPath, $mimeType, $filename, $size);
        }

        return $this->respondWithBinary($disk, $resolvedPath, $mimeType, $filename);
    }

    private function isVideoPath(string $normalizedPath): bool
    {
        $videoPrefix = trim((string) config('services.ai_media.video_prefix', 'ai/generated-videos'), '/');

        return str_starts_with($normalizedPath, $videoPrefix . '/');
    }

    private function respondWithBinary(
        Filesystem $disk,
        string $normalizedPath,
        string $mimeType,
        string $filename,
    ): Response {
        $content = $disk->get($normalizedPath);
        if (! is_string($content) || $content === '') {
            Log::error('AI media read failed', [
                'path' => $normalizedPath,
            ]);
            abort(404);
        }

        return response($content, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function respondWithStream(
        Request $request,
        Filesystem $disk,
        string $normalizedPath,
        string $mimeType,
        string $filename,
        int $size,
    ): StreamedResponse {
        [$start, $end, $status] = $this->resolveByteRange($request, $size);

        $headers = [
            'Content-Type' => $mimeType,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Content-Length' => (string) ($end - $start + 1),
            'Cache-Control' => 'private, max-age=3600',
        ];

        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $length = $end - $start + 1;

        return response()->stream(function () use ($disk, $normalizedPath, $start, $length): void {
            $stream = $disk->readStream($normalizedPath);
            if (! is_resource($stream)) {
                Log::error('AI media stream open failed', [
                    'path' => $normalizedPath,
                ]);

                return;
            }

            try {
                if ($start > 0) {
                    fseek($stream, $start);
                }

                $remaining = $length;
                while ($remaining > 0 && ! feof($stream)) {
                    $chunk = fread($stream, min(8192, $remaining));
                    if ($chunk === false) {
                        break;
                    }

                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($stream);
            }
        }, $status, $headers);
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function resolveByteRange(Request $request, int $size): array
    {
        $rangeHeader = (string) $request->header('Range', '');
        if ($rangeHeader === '' || ! preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches)) {
            return [0, max(0, $size - 1), 200];
        }

        $start = $matches[1] !== '' ? (int) $matches[1] : 0;
        $end = $matches[2] !== '' ? (int) $matches[2] : max(0, $size - 1);

        if ($size <= 0 || $start > $end || $start >= $size) {
            abort(416);
        }

        return [$start, min($end, $size - 1), 206];
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

        $normalizedPath = implode('/', $segments);

        $normalizedPath = $this->aiMediaStorageService->normalizeStoragePath($normalizedPath);
        if ($normalizedPath === '') {
            return null;
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