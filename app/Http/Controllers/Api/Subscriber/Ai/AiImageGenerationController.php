<?php

namespace App\Http\Controllers\Api\Subscriber\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiImageGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiImageGenerationController extends Controller
{
    public function __construct(
        private readonly AiImageGenerationService $aiImageGenerationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $subscriberId = (int) data_get($request->user(), 'subscriber.id');
        if ($subscriberId <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Подписчик не найден'],
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => $this->aiImageGenerationService->listForSubscriber($subscriberId),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (int) ($user?->id ?? 0);
        $subscriberId = (int) data_get($user, 'subscriber.id');

        if ($userId <= 0 || $subscriberId <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Пользователь не авторизован'],
            ], 401);
        }

        $generation = $this->aiImageGenerationService->create($subscriberId, $userId);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $generation->id,
                'title' => $generation->title,
                'created_at' => $generation->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $subscriberId = (int) data_get($request->user(), 'subscriber.id');
        if ($subscriberId <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Подписчик не найден'],
            ], 401);
        }

        $generation = $this->aiImageGenerationService->show($id, $subscriberId);
        if ($generation === null) {
            return response()->json([
                'success' => false,
                'messages' => ['Генерация не найдена'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $generation,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $subscriberId = (int) data_get($request->user(), 'subscriber.id');
        if ($subscriberId <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Подписчик не найден'],
            ], 401);
        }

        if (! $this->aiImageGenerationService->delete($id, $subscriberId)) {
            return response()->json([
                'success' => false,
                'messages' => ['Генерация не найдена'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Генерация удалена'],
        ]);
    }
}