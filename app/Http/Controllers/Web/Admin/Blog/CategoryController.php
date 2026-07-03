<?php

namespace App\Http\Controllers\Web\Admin\Blog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\StoreCategoryRequest;
use App\Http\Requests\Blog\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\Blog\BlogSlugService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(private readonly BlogSlugService $blogSlugService)
    {
    }

    public function index(): Response
    {
        $categories = Category::query()->orderBy('name')->get();

        return Inertia::render('Admin/Blog/Categories/Index', [
            'categories' => CategoryResource::collection($categories)->resolve(),
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $slugSource = ! empty($data['slug']) ? $data['slug'] : $data['name'];

        Category::create([
            'name' => $data['name'],
            'slug' => $this->blogSlugService->generateUniqueSlug(new Category(), $slugSource),
        ]);

        return redirect()->back()->with('success', 'Категория создана');
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $data = $request->validated();
        $slugSource = ! empty($data['slug']) ? $data['slug'] : $data['name'];

        $category->update([
            'name' => $data['name'],
            'slug' => $this->blogSlugService->generateUniqueSlug(new Category(), $slugSource, $category->id),
        ]);

        return redirect()->back()->with('success', 'Категория обновлена');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $category->delete();

        return redirect()->back()->with('success', 'Категория удалена');
    }
}