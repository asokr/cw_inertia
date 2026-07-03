<?php

namespace App\Http\Controllers\Web\Admin\Blog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\StoreTagRequest;
use App\Http\Requests\Blog\UpdateTagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Services\Blog\BlogSlugService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    public function __construct(private readonly BlogSlugService $blogSlugService)
    {
    }

    public function index(): Response
    {
        $tags = Tag::query()->orderBy('name')->get();

        return Inertia::render('Admin/Blog/Tags/Index', [
            'tags' => TagResource::collection($tags)->resolve(),
        ]);
    }

    public function store(StoreTagRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $slugSource = ! empty($data['slug']) ? $data['slug'] : $data['name'];

        Tag::create([
            'name' => $data['name'],
            'slug' => $this->blogSlugService->generateUniqueSlug(new Tag(), $slugSource),
        ]);

        return redirect()->back()->with('success', 'Тег создан');
    }

    public function update(UpdateTagRequest $request, Tag $tag): RedirectResponse
    {
        $data = $request->validated();
        $slugSource = ! empty($data['slug']) ? $data['slug'] : $data['name'];

        $tag->update([
            'name' => $data['name'],
            'slug' => $this->blogSlugService->generateUniqueSlug(new Tag(), $slugSource, $tag->id),
        ]);

        return redirect()->back()->with('success', 'Тег обновлён');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $tag->delete();

        return redirect()->back()->with('success', 'Тег удалён');
    }
}