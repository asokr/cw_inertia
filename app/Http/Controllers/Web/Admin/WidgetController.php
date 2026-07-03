<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetController extends Controller
{
    public function lastBlogPosts(Request $request): JsonResponse
    {
        $rows = min(max((int) $request->query('rows', 5), 1), 20);

        $posts = Post::query()
            ->with(['categories'])
            ->orderByDesc('id')
            ->limit($rows)
            ->get();

        return response()->json([
            'success' => true,
            'messages' => ['Последние посты блога'],
            'data' => PostResource::collection($posts)->resolve(),
        ]);
    }
}