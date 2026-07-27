<?php

namespace App\Services\Blog;

use App\Http\Resources\CategoryResource;
use App\Http\Resources\Subscriber\Blog\SubscriberPostResource;
use App\Models\Category;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PublicBlogService
{
    public function __construct(private readonly BlogCacheService $blogCacheService)
    {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function listPublished(array $filters, int $page = 1): array
    {
        $cacheKeyFilters = $filters;
        $cacheKeyFilters['page'] = $page;

        $payload = $this->blogCacheService->getSubscriberPosts($cacheKeyFilters, function () use ($filters, $page) {
            $query = $this->publishedPostsQuery($filters);

            $perPage = (int) ($filters['per_page'] ?? 12);
            $posts = $query
                ->paginate($perPage, ['*'], 'page', $page)
                ->appends(array_filter([
                    'search' => $filters['search'] ?? null,
                    'category_id' => $filters['category_id'] ?? null,
                    'tag_id' => $filters['tag_id'] ?? null,
                    'per_page' => $perPage !== 12 ? $perPage : null,
                ]));

            return SubscriberPostResource::collection($posts)->response()->getData(true);
        });

        return [
            'data' => $payload['data'] ?? [],
            'pagination' => [
                'current_page' => $payload['meta']['current_page'] ?? 1,
                'last_page' => $payload['meta']['last_page'] ?? 1,
                'per_page' => $payload['meta']['per_page'] ?? 12,
                'total' => $payload['meta']['total'] ?? 0,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCategories(): array
    {
        return CategoryResource::collection(
            Category::query()
                ->whereHas('posts', fn (Builder $query) => $query->published())
                ->orderBy('name')
                ->get()
        )->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPublishedBySlug(string $slug): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->blogCacheService->getSubscriberPostBySlug($slug, function () use ($slug) {
            $model = Post::query()
                ->published()
                ->where('slug', $slug)
                ->with(['categories', 'tags'])
                ->withCount(['categories', 'tags'])
                ->firstOrFail();

            $this->blogCacheService->rememberSubscriberPostIdSlug($model->id, $model->slug);

            // Cache plain arrays, not Eloquent models — unserialized models can become
            // __PHP_Incomplete_Class and blow up on property writes (production.ERROR).
            return (new SubscriberPostResource($model))->resolve();
        });

        // Optimistic display (+1) for the current request; real increment is POST /view.
        // Do not mutate the cached value — only the local response copy.
        if (array_key_exists('views_count', $payload)) {
            $payload['views_count'] = (int) $payload['views_count'] + 1;
        }

        return $payload;
    }

    public function incrementView(string $slug): void
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

        $this->blogCacheService->clearSubscriberPostCache($slug);
    }

    public function getSitemapXml(): string
    {
        return $this->blogCacheService->getSitemapXml(function () {
            $baseUrl = rtrim((string) config('app.url'), '/');
            $posts = Post::query()
                ->published()
                ->select(['slug', 'updated_at', 'published_at'])
                ->orderBy('id')
                ->cursor();

            $lines = ['<?xml version="1.0" encoding="UTF-8"?>'];
            $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

            foreach ($posts as $post) {
                $loc = htmlspecialchars($baseUrl . '/blog/' . ltrim($post->slug, '/'), ENT_QUOTES | ENT_XML1, 'UTF-8');
                $lastmodAt = $post->updated_at ?? $post->published_at;
                $lastmod = $lastmodAt ? $lastmodAt->toAtomString() : now()->toAtomString();

                $lines[] = '  <url>';
                $lines[] = '    <loc>' . $loc . '</loc>';
                $lines[] = '    <lastmod>' . $lastmod . '</lastmod>';
                $lines[] = '    <changefreq>daily</changefreq>';
                $lines[] = '    <priority>0.8</priority>';
                $lines[] = '  </url>';
            }

            $lines[] = '</urlset>';

            return implode("\n", $lines);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function publishedPostsQuery(array $filters): Builder
    {
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

        return $query;
    }
}