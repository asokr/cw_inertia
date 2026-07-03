<?php

namespace App\Http\Controllers\Web\Blog;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function show(string $path): Response|StreamedResponse
    {
        $key = ltrim($path, '/');
        $allowedPrefix = (string) config('services.blog_media.allowed_prefix', 'blog/images/');

        if ($key === '' || ! str_starts_with($key, $allowedPrefix)) {
            abort(404);
        }

        if (str_contains($key, '..') || str_contains($key, "\0")) {
            abort(400);
        }

        if (! Storage::disk('public')->exists($key)) {
            abort(404);
        }

        $mime = Storage::disk('public')->mimeType($key) ?: 'application/octet-stream';

        return Storage::disk('public')->response($key, null, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}