<?php

namespace App\Http\Controllers\Web\Admin\Blog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\UploadBlogImageRequest;
use App\Services\Blog\BlogMediaService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class MediaController extends Controller
{
    public function __construct(private readonly BlogMediaService $blogMediaService)
    {
    }

    public function uploadImage(UploadBlogImageRequest $request): JsonResponse
    {
        try {
            $path = $this->blogMediaService->uploadImage($request->file('image'));
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'messages' => [$e->getMessage()],
                'data' => null,
            ], 200);
        }

        $basePath = '/' . trim((string) config('services.blog_media.public_base_path', '/media'), '/');
        $publicPath = $basePath . '/' . ltrim($path, '/');

        return response()->json([
            'success' => true,
            'messages' => ['Изображение загружено'],
            'data' => [
                'path' => $path,
                'public_path' => $publicPath,
                'url' => $publicPath,
            ],
        ], 200);
    }
}