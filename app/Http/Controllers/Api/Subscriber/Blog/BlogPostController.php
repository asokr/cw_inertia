<?php

namespace App\Http\Controllers\Api\Subscriber\Blog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\IndexSubscriberPostRequest;
use App\Http\Resources\Subscriber\Blog\SubscriberPostResource;
use App\Models\Post;
use App\Services\Blog\BlogCacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BlogPostController extends Controller
{
    public function __construct(private readonly BlogCacheService $blogCacheService)
    {
    }

    public function index(IndexSubscriberPostRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $cacheKeyFilters = $filters;
        $cacheKeyFilters['page'] = (int) $request->query('page', 1);

        $data = $this->blogCacheService->getSubscriberPosts($cacheKeyFilters, function () use ($request, $filters) {
            $query = Post::query()
                ->published()
                ->with(['categories', 'tags'])
                ->withCount(['categories', 'tags'])
                ->orderByDesc('published_at')
                ->orderByDesc('id');

            if (! empty($filters['category_id'])) {
                $query->whereHas('categories', function (Builder $builder) use ($filters) {
                    $builder->where('categories.id', $filters['category_id']);
                });
            }

            if (! empty($filters['tag_id'])) {
                $query->whereHas('tags', function (Builder $builder) use ($filters) {
                    $builder->where('tags.id', $filters['tag_id']);
                });
            }

            $query->search($filters['search'] ?? null);

            $perPage = (int) ($filters['per_page'] ?? 15);
            $posts = $query
                ->paginate($perPage)
                ->appends($request->query());

            return SubscriberPostResource::collection($posts)->response()->getData(true);
        });

        return response()->json([
            'success' => true,
            'messages' => ['Список опубликованных постов блога'],
            'data' => $data,
        ], 200);
    }

    public function show(string $slug): JsonResponse
    {
        /** @var Post $post */
        $post = $this->blogCacheService->getSubscriberPostBySlug($slug, function () use ($slug) {
            $model = Post::query()
                ->published()
                ->where('slug', $slug)
                ->with(['categories', 'tags'])
                ->withCount(['categories', 'tags'])
                ->firstOrFail();

            $this->blogCacheService->rememberSubscriberPostIdSlug($model->id, $model->slug);

            return $model;
        });

        $post->views_count++;

        return response()->json([
            'success' => true,
            'messages' => ['Пост блога'],
            'data' => new SubscriberPostResource($post),
        ], 200);
    }

    public function incrementView(string $slug): JsonResponse
    {
        $post = Post::query()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        Post::query()
            ->whereKey($post->id)
            ->update([
                'views_count' => DB::raw('views_count + 1'),
            ]);

        return response()->json([
            'success' => true,
            'messages' => ['Просмотр поста увеличен'],
            'data' => null,
        ], 200);
    }
}
