<?php

namespace App\Http\Controllers\Web\Blog;

use App\Http\Controllers\Controller;
use App\Services\Blog\PublicBlogService;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __construct(private readonly PublicBlogService $blogService)
    {
    }

    public function index(): Response
    {
        return response($this->blogService->getSitemapXml(), 200, [
            'Content-Type' => 'text/xml; charset=UTF-8',
        ]);
    }
}