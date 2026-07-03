<?php

namespace App\Services\Blog;

use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

class BlogCacheService
{
    public function getPosts(array $params, Closure $callback): mixed
    {
        ksort($params);

        $key = 'blog_posts_' . $this->getPostsVersion() . '_' . md5(json_encode($params, JSON_UNESCAPED_UNICODE));

        if (! $this->supportsTags()) {
            return Cache::remember($key, 600, $callback);
        }

        return Cache::tags(['blog_posts'])->remember($key, 600, $callback);
    }

    public function getSubscriberPosts(array $params, Closure $callback): mixed
    {
        ksort($params);

        $key = 'blog_subscriber_posts_' . $this->getPostsVersion() . '_' . md5(json_encode($params, JSON_UNESCAPED_UNICODE));

        if (! $this->supportsTags()) {
            return Cache::remember($key, 600, $callback);
        }

        return Cache::tags(['blog_posts'])->remember($key, 600, $callback);
    }

    public function getSubscriberPostBySlug(string $slug, Closure $callback): mixed
    {
        return Cache::remember('blog_subscriber_post_slug_' . $slug, 1800, $callback);
    }

    public function clearSubscriberPostCache(string $slug): void
    {
        Cache::forget('blog_subscriber_post_slug_' . $slug);
    }

    public function getSitemapXml(Closure $callback): string
    {
        return Cache::remember('blog_sitemap_xml', 3600, $callback);
    }

    public function clearSitemapCache(): void
    {
        Cache::forget('blog_sitemap_xml');
    }

    public function clearSubscriberPostCacheById(int $id): void
    {
        $slug = Cache::get('blog_subscriber_post_id_to_slug_' . $id);
        if ($slug) {
            $this->clearSubscriberPostCache($slug);
        }
    }

    public function rememberSubscriberPostIdSlug(int $id, string $slug): void
    {
        Cache::put('blog_subscriber_post_id_to_slug_' . $id, $slug, 1800);
    }

    public function forgetSubscriberPostIdSlug(int $id): void
    {
        Cache::forget('blog_subscriber_post_id_to_slug_' . $id);
    }

    public function getPost(int $id, Closure $callback): mixed
    {
        return Cache::remember('blog_post_' . $id, 1800, $callback);
    }

    public function clearPostsCache(): void
    {
        if ($this->supportsTags()) {
            Cache::tags(['blog_posts'])->flush();
        }

        Cache::forever('blog_posts_cache_version', $this->getPostsVersion() + 1);
    }

    public function clearPostCache(int $id): void
    {
        Cache::forget('blog_post_' . $id);
        $this->clearSubscriberPostCacheById($id);
        $this->forgetSubscriberPostIdSlug($id);
    }

    private function getPostsVersion(): int
    {
        return (int) Cache::get('blog_posts_cache_version', 1);
    }

    private function supportsTags(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }
}
