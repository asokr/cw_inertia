<?php

namespace App\Http\Controllers\Web\Blog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\IndexSubscriberPostRequest;
use App\Services\Blog\PublicBlogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BlogPostController extends Controller
{
    public function __construct(private readonly PublicBlogService $blogService)
    {
    }

    public function index(IndexSubscriberPostRequest $request): Response
    {
        $filters = $request->validated();
        $page = max(1, (int) $request->query('page', 1));
        $filters['per_page'] = (int) ($filters['per_page'] ?? 12);

        $result = $this->blogService->listPublished($filters, $page);

        return Inertia::render('Blog/Index', [
            'posts' => $result['data'],
            'pagination' => $result['pagination'],
            'categories' => $this->blogService->listCategories(),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'category_id' => isset($filters['category_id']) ? (int) $filters['category_id'] : null,
                'tag_id' => isset($filters['tag_id']) ? (int) $filters['tag_id'] : null,
            ],
        ]);
    }

    public function show(string $slug): Response
    {
        return Inertia::render('Blog/Show', [
            'post' => $this->blogService->getPublishedBySlug($slug),
        ]);
    }

    public function incrementView(string $slug): JsonResponse|RedirectResponse
    {
        $this->blogService->incrementView($slug);

        if (request()->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return back();
    }
}