<?php

namespace App\Http\Controllers\Web\Subscriber\Ai;

use App\Http\Controllers\Api\Subscriber\Ai\AiImageController as ApiAiImageController;
use App\Http\Controllers\Api\Subscriber\Ai\AiImageGenerationController as ApiAiImageGenerationController;
use App\Http\Controllers\Api\Subscriber\Ai\AiVideoGenerationController as ApiAiVideoGenerationController;
use App\Http\Controllers\Api\Subscriber\Ai\GeminiController as ApiGeminiController;
use App\Http\Controllers\Api\Subscriber\Ai\GrokVideoController as ApiGrokVideoController;
use App\Http\Controllers\Api\Subscriber\User\ProfileController as ApiProfileController;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\RefreshAiLimitsRequest;
use App\Http\Requests\Web\Subscriber\RunAiMarketplaceRequest;
use App\Http\Requests\Web\Subscriber\StartAiImageRequest;
use App\Http\Requests\Web\Subscriber\StartAiReferenceVideoRequest;
use App\Http\Requests\Web\Subscriber\StartAiVideoRequest;
use App\Models\Subscribers\SubscribersSubscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceController extends SubscriberToolController
{
    public function __construct(
        private readonly ApiGeminiController $apiGeminiController,
        private readonly ApiAiImageController $apiAiImageController,
        private readonly ApiGrokVideoController $apiGrokVideoController,
        private readonly ApiAiImageGenerationController $apiAiImageGenerationController,
        private readonly ApiAiVideoGenerationController $apiAiVideoGenerationController,
        private readonly ApiProfileController $apiProfileController,
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

    public function marketplace(RunAiMarketplaceRequest $request): JsonResponse
    {
        $response = $this->apiGeminiController->marketplace($request);

        return response()->json($this->decodeApiResponse($response), $response->getStatusCode());
    }

    public function imageStart(StartAiImageRequest $request): JsonResponse
    {
        $response = $this->apiAiImageController->start($request);

        return response()->json($this->decodeApiResponse($response), $response->getStatusCode());
    }

    public function imageGenerationsIndex(Request $request): JsonResponse
    {
        $response = $this->apiAiImageGenerationController->index($request);

        return response()->json($this->decodeApiResponse($response), $response->getStatusCode());
    }

    public function imageGenerationsStore(Request $request): JsonResponse
    {
        $response = $this->apiAiImageGenerationController->store($request);

        return response()->json($this->decodeApiResponse($response), $response->getStatusCode());
    }

    public function imageGenerationsShow(Request $request, int $id): JsonResponse
    {
        $response = $this->apiAiImageGenerationController->show($request, $id);

        return response()->json($this->decodeApiResponse($response), $response->getStatusCode());
    }

    public function imageGenerationsDestroy(Request $request, int $id): JsonResponse
    {
        $response = $this->apiAiImageGenerationController->destroy($request, $id);

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

    public function videoGenerationsIndex(Request $request): JsonResponse
    {
        $response = $this->apiAiVideoGenerationController->index($request);

        return response()->json($this->decodeApiResponse($response), $response->getStatusCode());
    }

    public function videoGenerationsStore(Request $request): JsonResponse
    {
        $response = $this->apiAiVideoGenerationController->store($request);

        return response()->json($this->decodeApiResponse($response), $response->getStatusCode());
    }

    public function videoGenerationsShow(Request $request, int $id): JsonResponse
    {
        $response = $this->apiAiVideoGenerationController->show($request, $id);

        return response()->json($this->decodeApiResponse($response), $response->getStatusCode());
    }

    public function videoGenerationsDestroy(Request $request, int $id): JsonResponse
    {
        $response = $this->apiAiVideoGenerationController->destroy($request, $id);

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