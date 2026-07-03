<?php

namespace App\Http\Controllers\Api\Subscriber\Blog;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Blog\BlogCacheService;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __construct(private readonly BlogCacheService $blogCacheService)
    {
    }

    public function index(): Response
    {
        $xml = $this->blogCacheService->getSitemapXml(function () {
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

        return response($xml, 200, [
            'Content-Type' => 'text/xml; charset=UTF-8',
        ]);
    }
}
