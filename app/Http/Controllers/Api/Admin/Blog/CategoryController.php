<?php

namespace App\Http\Controllers\Api\Admin\Blog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\StoreCategoryRequest;
use App\Http\Requests\Blog\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\Blog\BlogSlugService;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function __construct(private readonly BlogSlugService $blogSlugService)
    {
    }

    public function index(): JsonResponse
    {
        $categories = Category::query()->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'messages' => ['Список категорий блога'],
            'data' => CategoryResource::collection($categories),
        ], 200);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $slugSource = ! empty($data['slug']) ? $data['slug'] : $data['name'];

        $category = Category::create([
            'name' => $data['name'],
            'slug' => $this->blogSlugService->generateUniqueSlug(new Category(), $slugSource),
        ]);

        return response()->json([
            'success' => true,
            'messages' => ['Категория создана'],
            'data' => new CategoryResource($category),
        ], 200);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $data = $request->validated();
        $slugSource = ! empty($data['slug']) ? $data['slug'] : $data['name'];

        $category->update([
            'name' => $data['name'],
            'slug' => $this->blogSlugService->generateUniqueSlug(new Category(), $slugSource, $category->id),
        ]);

        return response()->json([
            'success' => true,
            'messages' => ['Категория обновлена'],
            'data' => new CategoryResource($category),
        ], 200);
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->delete();

        return response()->json([
            'success' => true,
            'messages' => ['Категория удалена'],
            'data' => null,
        ], 200);
    }
}
