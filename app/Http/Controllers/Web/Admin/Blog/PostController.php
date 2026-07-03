<?php

namespace App\Http\Controllers\Web\Admin\Blog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\IndexPostRequest;
use App\Http\Requests\Blog\StorePostRequest;
use App\Http\Requests\Blog\UpdatePostRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\TagResource;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Services\Blog\BlogCacheService;
use App\Services\Blog\BlogSlugService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function __construct(
        private readonly BlogSlugService $blogSlugService,
        private readonly BlogCacheService $blogCacheService,
    ) {
    }

    public function index(IndexPostRequest $request): Response
    {
        $filters = $request->validated();
        $page = (int) $request->query('page', 1);
        $cacheKeyFilters = array_merge($filters, ['page' => $page]);

        $data = $this->blogCacheService->getPosts($cacheKeyFilters, function () use ($request, $filters) {
            $query = Post::query()
                ->with(['categories', 'tags'])
                ->withCount(['categories', 'tags'])
                ->orderByDesc('id');

            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            $query->search($filters['search'] ?? null);

            $perPage = (int) ($filters['per_page'] ?? 15);

            return PostResource::collection(
                $query->paginate($perPage)->appends($request->query())
            )->response()->getData(true);
        });

        return Inertia::render('Admin/Blog/Posts/Index', [
            'posts' => $data,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? null,
                'per_page' => (int) ($filters['per_page'] ?? 15),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Blog/Posts/Form', [
            'post' => null,
            'categories' => CategoryResource::collection(Category::orderBy('name')->get())->resolve(),
            'tags' => TagResource::collection(Tag::orderBy('name')->get())->resolve(),
        ]);
    }

    public function edit(Post $post): Response
    {
        $post->load(['categories', 'tags'])->loadCount(['categories', 'tags']);

        return Inertia::render('Admin/Blog/Posts/Form', [
            'post' => (new PostResource($post))->resolve(),
            'categories' => CategoryResource::collection(Category::orderBy('name')->get())->resolve(),
            'tags' => TagResource::collection(Tag::orderBy('name')->get())->resolve(),
        ]);
    }

    public function store(StorePostRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data = $this->preparePublishedAt($data);
        $this->ensurePublishPermission($data);

        DB::transaction(function () use ($data, $request) {
            $slug = $this->blogSlugService->generateUniqueSlug(new Post(), $data['slug'] ?? $data['title']);

            $post = Post::create([
                'title' => $data['title'],
                'slug' => $slug,
                'content' => $data['content'],
                'excerpt' => $data['excerpt'] ?? null,
                'cover_image' => $data['cover_image'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'published_at' => $data['published_at'] ?? null,
                'views_count' => $data['views_count'] ?? 0,
                'seo_title' => $data['seo_title'] ?? null,
                'seo_description' => $data['seo_description'] ?? null,
                'seo_keywords' => $data['seo_keywords'] ?? null,
                'author_id' => $data['author_id'] ?? $request->user()?->id,
            ]);

            $post->categories()->sync($data['categories'] ?? []);
            $post->tags()->sync($data['tags'] ?? []);
        });

        $this->clearCaches();

        return redirect()->route('admin.blog.posts.index')->with('success', 'Пост создан');
    }

    public function update(UpdatePostRequest $request, Post $post): RedirectResponse
    {
        $data = $request->validated();
        $data = $this->preparePublishedAt($data, $post);
        $this->ensurePublishPermission($data, $post);

        DB::transaction(function () use ($post, $data) {
            $updateData = $data;
            unset($updateData['categories'], $updateData['tags']);

            if (array_key_exists('slug', $updateData)) {
                if ($updateData['slug'] !== null && trim((string) $updateData['slug']) !== '') {
                    $updateData['slug'] = $this->blogSlugService->generateUniqueSlug(new Post(), $updateData['slug'], $post->id);
                } else {
                    unset($updateData['slug']);
                }
            }

            $post->update($updateData);

            if (array_key_exists('categories', $data)) {
                $post->categories()->sync($data['categories'] ?? []);
            }

            if (array_key_exists('tags', $data)) {
                $post->tags()->sync($data['tags'] ?? []);
            }
        });

        $this->clearCaches($post->id);

        return redirect()->route('admin.blog.posts.index')->with('success', 'Пост обновлён');
    }

    public function destroy(Post $post): RedirectResponse
    {
        $postId = $post->id;
        $post->delete();
        $this->clearCaches($postId);

        return redirect()->back()->with('success', 'Пост удалён');
    }

    private function clearCaches(?int $postId = null): void
    {
        $this->blogCacheService->clearPostsCache();
        $this->blogCacheService->clearSitemapCache();

        if ($postId) {
            $this->blogCacheService->clearPostCache($postId);
        }
    }

    private function ensurePublishPermission(array $data, ?Post $post = null): void
    {
        $statusChanged = array_key_exists('status', $data)
            && ($post === null || $data['status'] !== $post->status);

        $publishedAtChanged = array_key_exists('published_at', $data)
            && ($post === null || (string) ($data['published_at'] ?? '') !== (string) optional($post->published_at)?->toDateTimeString());

        if (! $statusChanged && ! $publishedAtChanged) {
            return;
        }

        if (! request()->user()?->can('blog.publish')) {
            abort(403);
        }
    }

    private function preparePublishedAt(array $data, ?Post $post = null): array
    {
        $status = $data['status'] ?? $post?->status;
        $hasPublishedAt = array_key_exists('published_at', $data);

        if ($post === null && ! $hasPublishedAt) {
            $data['published_at'] = Carbon::now();

            return $data;
        }

        if ($status === 'published' && ! $hasPublishedAt) {
            $data['published_at'] = Carbon::now();
        }

        return $data;
    }
}