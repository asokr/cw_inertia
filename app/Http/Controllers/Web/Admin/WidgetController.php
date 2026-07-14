<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\Admin\AdminWidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WidgetController extends Controller
{
    public function __construct(private readonly AdminWidgetService $widgetService)
    {
    }

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

    public function lastSubscriptions(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rows' => 'required',
            'sortField' => '',
            'sortOrder' => '',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $data = $this->widgetService->lastSubscriptions(
            (int) $request->input('rows'),
            $request->input('sortField', 'renewed_at'),
            $request->input('sortOrder', '-1'),
        );

        return response()->json([
            'success' => true,
            'messages' => 'Данные получены',
            'data' => $data,
        ], 200);
    }

    public function lastRegistered(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rows' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $subscribers = $this->widgetService->lastRegistered(
            (int) $request->input('rows', 10),
            (int) $request->input('page', 1),
        );

        return response()->json([
            'success' => true,
            'messages' => ['Данные получены'],
            'data' => $subscribers,
        ], 200);
    }
}