<?php

namespace App\Http\Controllers\Api\Subscriber\Ai;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiMediaController extends Controller
{
    public function show(Request $request, string $path): BinaryFileResponse|StreamedResponse
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

        $mimeType = (string) ($disk->mimeType($normalizedPath) ?: 'application/octet-stream');
        $size = (int) $disk->size($normalizedPath);
        $filename = basename($normalizedPath);

        if ($this->usesLocalDisk($diskName)) {
            return $this->respondWithLocalFile($request, $disk, $normalizedPath, $mimeType, $filename);
        }

        return $this->respondWithStream($request, $disk, $normalizedPath, $mimeType, $filename, $size);
    }

    private function usesLocalDisk(string $diskName): bool
    {
        return (string) config("filesystems.disks.{$diskName}.driver") === 'local';
    }

    private function respondWithLocalFile(
        Request $request,
        Filesystem $disk,
        string $normalizedPath,
        string $mimeType,
        string $filename,
    ): BinaryFileResponse {
        $response = new BinaryFileResponse(
            $disk->path($normalizedPath),
            200,
            [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
            ],
            true,
            null,
            false,
            true,
        );

        $response->setAutoEtag();

        return $response->prepare($request);
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
        ];

        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $length = $end - $start + 1;

        return response()->stream(function () use ($disk, $normalizedPath, $start, $length): void {
            $stream = $disk->readStream($normalizedPath);
            if (! is_resource($stream)) {
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