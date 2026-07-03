<?php

namespace App\Http\Controllers\Api\Admin\Blog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\IndexPostRequest;
use App\Http\Requests\Blog\StorePostRequest;
use App\Http\Requests\Blog\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\Blog\BlogCacheService;
use App\Services\Blog\BlogSlugService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PostController extends Controller
{
    public function __construct(
        private readonly BlogSlugService $blogSlugService,
        private readonly BlogCacheService $blogCacheService,
    ) {
    }

    public function index(IndexPostRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $cacheKeyFilters = $filters;
        $cacheKeyFilters['page'] = (int) $request->query('page', 1);

        $data = $this->blogCacheService->getPosts($cacheKeyFilters, function () use ($request, $filters) {
            $query = Post::query()
                ->with(['categories', 'tags'])
                ->withCount(['categories', 'tags'])
                ->orderByDesc('id');

            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (! empty($filters['category_id'])) {
                $query->whereHas('categories', function ($builder) use ($filters) {
                    $builder->where('categories.id', $filters['category_id']);
                });
            }

            if (! empty($filters['tag_id'])) {
                $query->whereHas('tags', function ($builder) use ($filters) {
                    $builder->where('tags.id', $filters['tag_id']);
                });
            }

            $query->search($filters['search'] ?? null);

            $perPage = (int) ($filters['per_page'] ?? 15);
            $posts = $query
                ->paginate($perPage)
                ->appends($request->query());

            return PostResource::collection($posts)->response()->getData(true);
        });

        return response()->json([
            'success' => true,
            'messages' => ['Список постов блога'],
            'data' => $data,
        ], 200);
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data = $this->preparePublishedAt($data);
        $this->ensurePublishPermission($data);

        $post = DB::transaction(function () use ($data, $request) {
            $slugSource = $data['slug'] ?? $data['title'];
            $slug = $this->blogSlugService->generateUniqueSlug(new Post(), $slugSource);

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
                'author_id' => $data['author_id'] ?? optional($request->user())->id,
            ]);

            $post->categories()->sync($data['categories'] ?? []);
            $post->tags()->sync($data['tags'] ?? []);

            return $post->load(['categories', 'tags']);
        });

        $this->blogCacheService->clearPostsCache();
        $this->blogCacheService->clearPostCache($post->id);
        $this->blogCacheService->clearSitemapCache();

        return response()->json([
            'success' => true,
            'messages' => ['Пост блога создан'],
            'data' => new PostResource($post),
        ], 200);
    }

    public function show(Post $post): JsonResponse
    {
        $data = $this->blogCacheService->getPost($post->id, function () use ($post) {
            $model = Post::query()
                ->with(['categories', 'tags'])
                ->withCount(['categories', 'tags'])
                ->findOrFail($post->id);

            return (new PostResource($model))->resolve();
        });

        return response()->json([
            'success' => true,
            'messages' => ['Пост блога'],
            'data' => $data,
        ], 200);
    }

    public function update(UpdatePostRequest $request, Post $post): JsonResponse
    {
        $data = $request->validated();
        $data = $this->preparePublishedAt($data, $post);
        $this->ensurePublishPermission($data, $post);

        DB::transaction(function () use ($post, $data) {
            $updateData = $data;
            unset($updateData['categories'], $updateData['tags']);

            if (array_key_exists('slug', $updateData)) {
                if ($updateData['slug'] !== null && trim($updateData['slug']) !== '') {
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

        $this->blogCacheService->clearPostsCache();
        $this->blogCacheService->clearPostCache($post->id);
        $this->blogCacheService->clearSitemapCache();

        $post->load(['categories', 'tags'])->loadCount(['categories', 'tags']);

        return response()->json([
            'success' => true,
            'messages' => ['Пост блога обновлен'],
            'data' => new PostResource($post),
        ], 200);
    }

    public function destroy(Post $post): JsonResponse
    {
        $postId = $post->id;
        $post->delete();

        $this->blogCacheService->clearPostsCache();
        $this->blogCacheService->clearPostCache($postId);
        $this->blogCacheService->clearSitemapCache();

        return response()->json([
            'success' => true,
            'messages' => ['Пост блога удален'],
            'data' => null,
        ], 200);
    }

    public function incrementView(int $id): JsonResponse
    {
        $updated = Post::query()->where('id', $id)->update([
            'views_count' => DB::raw('views_count + 1'),
        ]);

        if (! $updated) {
            return response()->json([
                'success' => false,
                'messages' => ['Пост не найден'],
                'data' => null,
            ], 200);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Просмотр поста увеличен'],
            'data' => null,
        ], 200);
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

        if (! request()->user() || ! request()->user()->can('blog.publish')) {
            abort(403, 'Недостаточно прав для публикации постов');
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
