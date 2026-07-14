<?php

namespace App\Http\Controllers\Web\Subscriber\Ai;

use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\RefreshAiLimitsRequest;
use App\Http\Requests\Web\Subscriber\RunAiMarketplaceRequest;
use App\Http\Requests\Web\Subscriber\StartAiImageRequest;
use App\Http\Requests\Web\Subscriber\StartAiReferenceVideoRequest;
use App\Http\Requests\Web\Subscriber\StartAiVideoRequest;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Services\Ai\AiImageGenerationService;
use App\Services\Ai\AiVideoGenerationService;
use App\Services\Subscriber\Ai\SubscriberAiImageService;
use App\Services\Subscriber\Ai\SubscriberAiMarketplaceService;
use App\Services\Subscriber\Ai\SubscriberAiVideoService;
use App\Support\ToolLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceController extends SubscriberToolController
{
    public function __construct(
        private readonly SubscriberAiMarketplaceService $marketplaceService,
        private readonly SubscriberAiImageService $aiImageService,
        private readonly SubscriberAiVideoService $aiVideoService,
        private readonly AiImageGenerationService $aiImageGenerationService,
        private readonly AiVideoGenerationService $aiVideoGenerationService,
    ) {
    }

    public function index(): RedirectResponse
    {
        return redirect()->route('subscriber.ai.text');
    }

    public function text(Request $request): Response
    {
        return Inertia::render('Subscriber/Ai/Text', [
            'limits' => $this->loadLimits($request),
        ]);
    }

    public function image(Request $request): Response
    {
        return Inertia::render('Subscriber/Ai/Image', [
            'limits' => $this->loadLimits($request),
        ]);
    }

    public function imageGeneration(Request $request, string $uuid): Response
    {
        return Inertia::render('Subscriber/Ai/Image', [
            'limits' => $this->loadLimits($request),
            'generationUuid' => $uuid,
        ]);
    }

    public function imageHistory(Request $request): Response
    {
        return Inertia::render('Subscriber/Ai/ImageHistory', [
            'limits' => $this->loadLimits($request),
        ]);
    }

    public function video(Request $request): Response
    {
        return Inertia::render('Subscriber/Ai/Video', [
            'limits' => $this->loadLimits($request),
        ]);
    }

    public function videoGeneration(Request $request, string $uuid): Response
    {
        return Inertia::render('Subscriber/Ai/Video', [
            'limits' => $this->loadLimits($request),
            'generationUuid' => $uuid,
        ]);
    }

    public function videoHistory(Request $request): Response
    {
        return Inertia::render('Subscriber/Ai/VideoHistory', [
            'limits' => $this->loadLimits($request),
        ]);
    }

    public function marketplace(RunAiMarketplaceRequest $request): JsonResponse
    {
        return $this->marketplaceService->marketplace($request);
    }

    public function imageStart(StartAiImageRequest $request): JsonResponse
    {
        return $this->aiImageService->start($request);
    }

    public function imageGenerationsIndex(Request $request): JsonResponse
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

    public function imageGenerationsStore(Request $request): JsonResponse
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
                'uuid' => $generation->uuid,
                'title' => $generation->title,
                'created_at' => $generation->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function imageGenerationsShow(Request $request, string $uuid): JsonResponse
    {
        $subscriberId = (int) data_get($request->user(), 'subscriber.id');
        if ($subscriberId <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Подписчик не найден'],
            ], 401);
        }

        $generation = $this->aiImageGenerationService->showByUuid($uuid, $subscriberId);
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

    public function imageGenerationsDestroy(Request $request, string $uuid): JsonResponse
    {
        $subscriberId = (int) data_get($request->user(), 'subscriber.id');
        if ($subscriberId <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Подписчик не найден'],
            ], 401);
        }

        if (! $this->aiImageGenerationService->deleteByUuid($uuid, $subscriberId)) {
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

    public function videoStart(StartAiVideoRequest $request): JsonResponse
    {
        return $this->aiVideoService->start($request);
    }

    public function videoReferenceStart(StartAiReferenceVideoRequest $request): JsonResponse
    {
        return $this->aiVideoService->referenceStart($request);
    }

    public function videoStatus(string $requestId): JsonResponse
    {
        return $this->aiVideoService->status($requestId);
    }

    public function videoGenerationsIndex(Request $request): JsonResponse
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
            'data' => $this->aiVideoGenerationService->listForSubscriber($subscriberId),
        ]);
    }

    public function videoGenerationsStore(Request $request): JsonResponse
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

        $generation = $this->aiVideoGenerationService->create($subscriberId, $userId);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $generation->id,
                'uuid' => $generation->uuid,
                'title' => $generation->title,
                'created_at' => $generation->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function videoGenerationsShow(Request $request, string $uuid): JsonResponse
    {
        $subscriberId = (int) data_get($request->user(), 'subscriber.id');
        if ($subscriberId <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Подписчик не найден'],
            ], 401);
        }

        $generation = $this->aiVideoGenerationService->showByUuid($uuid, $subscriberId);
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

    public function videoGenerationsDestroy(Request $request, string $uuid): JsonResponse
    {
        $subscriberId = (int) data_get($request->user(), 'subscriber.id');
        if ($subscriberId <= 0) {
            return response()->json([
                'success' => false,
                'messages' => ['Подписчик не найден'],
            ], 401);
        }

        if (! $this->aiVideoGenerationService->deleteByUuid($uuid, $subscriberId)) {
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

    public function refreshLimits(RefreshAiLimitsRequest $request): JsonResponse
    {
        $subscription = $this->activeSubscription($request);
        $limit = ToolLimits::monthLimitValue(
            $request->user(),
            $subscription,
            $request->validated('limit'),
        );

        return response()->json([
            'success' => true,
            'messages' => ['Информация по лимиту'],
            'data' => $limit,
        ]);
    }

    /**
     * @return array{text: int, image: int, video: int}
     */
    private function loadLimits(Request $request): array
    {
        $subscription = $this->activeSubscription($request);

        return [
            'text' => $this->limitValue($subscription, 'ai_text_query'),
            'image' => $this->limitValue($subscription, 'ai_image_query'),
            'video' => $this->limitValue($subscription, 'ai_video_query'),
        ];
    }

    private function activeSubscription(Request $request): ?SubscribersSubscriptions
    {
        $subscriberId = $request->user()?->subscriber?->id;

        if (! $subscriberId) {
            return null;
        }

        return SubscribersSubscriptions::query()
            ->where([
                'subscribers_id' => $subscriberId,
                'status' => 1,
            ])
            ->first();
    }

    private function limitValue(?SubscribersSubscriptions $subscription, string $key): int
    {
        return ToolLimits::monthLimitValue(request()->user(), $subscription, $key);
    }
}