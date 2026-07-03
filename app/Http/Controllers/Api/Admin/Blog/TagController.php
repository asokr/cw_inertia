<?php

namespace App\Http\Controllers\Api\Admin\Blog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\StoreTagRequest;
use App\Http\Requests\Blog\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Services\Blog\BlogSlugService;
use Illuminate\Http\JsonResponse;

class TagController extends Controller
{
    public function __construct(private readonly BlogSlugService $blogSlugService)
    {
    }

    public function index(): JsonResponse
    {
        $tags = Tag::query()->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'messages' => ['Список тегов блога'],
            'data' => TagResource::collection($tags),
        ], 200);
    }

    public function store(StoreTagRequest $request): JsonResponse
    {
        $data = $request->validated();
        $slugSource = ! empty($data['slug']) ? $data['slug'] : $data['name'];

        $tag = Tag::create([
            'name' => $data['name'],
            'slug' => $this->blogSlugService->generateUniqueSlug(new Tag(), $slugSource),
        ]);

        return response()->json([
            'success' => true,
            'messages' => ['Тег создан'],
            'data' => new TagResource($tag),
        ], 200);
    }

    public function update(UpdateTagRequest $request, Tag $tag): JsonResponse
    {
        $data = $request->validated();
        $slugSource = ! empty($data['slug']) ? $data['slug'] : $data['name'];

        $tag->update([
            'name' => $data['name'],
            'slug' => $this->blogSlugService->generateUniqueSlug(new Tag(), $slugSource, $tag->id),
        ]);

        return response()->json([
            'success' => true,
            'messages' => ['Тег обновлен'],
            'data' => new TagResource($tag),
        ], 200);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $tag->delete();

        return response()->json([
            'success' => true,
            'messages' => ['Тег удален'],
            'data' => null,
        ], 200);
    }
}
