<?php

namespace App\Http\Controllers\Web\Subscriber\Ai;

use App\Http\Controllers\Api\Subscriber\Ai\GeminiController as ApiGeminiController;
use App\Http\Controllers\Api\Subscriber\Ai\GrokVideoController as ApiGrokVideoController;
use App\Http\Controllers\Api\Subscriber\User\ProfileController as ApiProfileController;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\RefreshAiLimitsRequest;
use App\Http\Requests\Web\Subscriber\RunAiMarketplaceRequest;
use App\Http\Requests\Web\Subscriber\StartAiReferenceVideoRequest;
use App\Http\Requests\Web\Subscriber\StartAiVideoRequest;
use App\Models\Subscribers\SubscribersSubscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceController extends SubscriberToolController
{
    public function __construct(
        private readonly ApiGeminiController $apiGeminiController,
        private readonly ApiGrokVideoController $apiGrokVideoController,
        private readonly ApiProfileController $apiProfileController,
    ) {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Subscriber/Ai/Index', [
            'limits' => $this->loadLimits($request),
        ]);
    }

    public function marketplace(RunAiMarketplaceRequest $request): JsonResponse
    {
        $response = $this->apiGeminiController->marketplace($request);

        return response()->json($this->decodeApiResponse($response), $response->getStatusCode());
    }

    public function videoStart(StartAiVideoRequest $request): JsonResponse
    {
        $response = $this->apiGrokVideoController->start($request);

        return response()->json($this->decodeApiResponse($response), $response->getStatusCode());
    }

    public function videoReferenceStart(StartAiReferenceVideoRequest $request): JsonResponse
    {
        $response = $this->apiGrokVideoController->referenceStart($request);

        return response()->json($this->decodeApiResponse($response), $response->getStatusCode());
    }

    public function videoStatus(string $requestId): JsonResponse
    {
        $response = $this->apiGrokVideoController->status($requestId);

        return response()->json($this->decodeApiResponse($response), $response->getStatusCode());
    }

    public function refreshLimits(RefreshAiLimitsRequest $request): JsonResponse
    {
        $response = $this->apiProfileController->remainingLimits($request);

        return response()->json($this->decodeApiResponse($response), $response->getStatusCode());
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
        if (! $subscription) {
            return 0;
        }

        $value = $subscription->getMonthLimit($key);

        return $value === false ? 0 : (int) $value;
    }
}