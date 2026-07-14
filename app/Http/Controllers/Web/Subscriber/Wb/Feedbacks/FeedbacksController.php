<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Feedbacks;

use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresFeedbacksClientOwnership;
use App\Services\Subscriber\Ai\SubscriberAiTextService;
use App\Services\Subscriber\Wb\WbFeedbacksClientsService;
use App\Services\Subscriber\Wb\WbFeedbacksService;
use App\Services\Subscriber\Wb\WbFeedbacksStatsService;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\SendFeedbackRequest;
use App\Http\Requests\Web\Subscriber\UpdateAiDataRequest;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Support\ToolLimits;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedbacksController extends SubscriberToolController
{
    use EnsuresFeedbacksClientOwnership;

    public function __construct(
        private readonly WbFeedbacksService $feedbacksService,
        private readonly WbFeedbacksClientsService $clientsService,
        private readonly WbFeedbacksStatsService $statsService,
        private readonly SubscriberAiTextService $aiTextService,
    ) {
    }

    public function show(Request $request, FeedbacksClients $client): Response
    {
        $this->ensureClientOwnership($client);

        $feedbacksPayload = $this->loadFeedbacks($request, $client, 0);
        $aiPayload = $this->decodeApiResponse(
            $this->clientsService->getAiData(
                $this->apiRequestWith($request, ['client_id' => $client->id])
            )
        );
        $answeredPayload = ['success' => true, 'data' => []];
        try {
            $answeredPayload = $this->decodeApiResponse(
                $this->statsService->answeredReviews(
                    $request->duplicate(['cabinet_id' => $client->id, 'limit' => 5])
                )
            );
        } catch (\Throwable) {
            $answeredPayload = ['success' => true, 'data' => []];
        }

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $request->user()->subscriber?->id)
            ->where('status', 1)
            ->first();

        return Inertia::render('Subscriber/Wb/Feedbacks/Client/Show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'feedbacks' => $feedbacksPayload['feedbacks'],
            'feedbacksError' => $feedbacksPayload['error'],
            'aiSettings' => ($aiPayload['success'] ?? false) ? ($aiPayload['data'] ?? null) : null,
            'aiLimit' => ToolLimits::monthLimitValue($request->user(), $subscription, 'feedbacks_gpt_query'),
            'answeredReviews' => ($answeredPayload['success'] ?? false) ? ($answeredPayload['data'] ?? []) : [],
            'ratingType' => ($aiPayload['data']['review_type'] ?? null),
        ]);
    }

    public function refresh(Request $request, FeedbacksClients $client): RedirectResponse
    {
        $this->ensureClientOwnership($client);

        $feedbacksPayload = $this->loadFeedbacks($request, $client, 0);

        if ($feedbacksPayload['error']) {
            return redirect()
                ->route('subscriber.wb.feedbacks.clients.show', $client)
                ->with('error', $feedbacksPayload['error']);
        }

        return redirect()
            ->route('subscriber.wb.feedbacks.clients.show', $client)
            ->with('success', 'Данные обновлены');
    }

    public function send(SendFeedbackRequest $request, FeedbacksClients $client): RedirectResponse
    {
        $this->ensureClientOwnership($client);

        $response = $this->feedbacksService->sendFeedbackToWb(
            $this->apiRequestWith($request, [
                'client_id' => $client->id,
                'id' => $request->validated('id'),
                'text' => $request->validated('text'),
            ])
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось отправить ответ'));
        }

        return redirect()
            ->route('subscriber.wb.feedbacks.clients.show', $client)
            ->with('success', $this->apiMessage($payload, 'Ответ отправлен'));
    }

    public function updateAi(UpdateAiDataRequest $request, FeedbacksClients $client): JsonResponse
    {
        $this->ensureClientOwnership($client);

        $response = $this->clientsService->updateAiData(
            $this->apiRequestWith($request, [
                'client_id' => $client->id,
                'status' => $request->validated('status'),
                'ratings' => $request->validated('ratings'),
                'review_type' => $request->input('review_type'),
            ])
        );

        return response()->json($this->decodeApiResponse($response));
    }

    public function generateAi(Request $request, FeedbacksClients $client): JsonResponse
    {
        $this->ensureClientOwnership($client);

        $validated = $request->validate([
            'feedback' => ['nullable', 'array'],
            'rating_type' => ['nullable', 'string'],
            'prompt' => ['nullable', 'string', 'min:10', 'max:4000'],
            'type' => ['nullable', 'string', 'max:500'],
        ]);

        if (! empty($validated['prompt'])) {
            $aiRequest = $request->duplicate();
            $aiRequest->merge([
                'prompt' => $validated['prompt'],
                'type' => $validated['type'] ?? 'копирайтер, задача которого отвечать на отзывы покупателей маркетплейса',
                'for' => 'feedbacks',
            ]);
        } else {
            $feedback = $validated['feedback'] ?? [];
            $reviewTypeSuffix = ($validated['rating_type'] ?? '') === 'stih' ? ' в стихах ' : ' ';
            $type = ($validated['rating_type'] ?? '') === 'stih'
                ? 'поэт, задача которого отвечать на отзывы покупателей маркетплейса'
                : 'копирайтер, задача которого отвечать на отзывы покупателей маркетплейса';

            $authorName = ! empty($feedback['name'])
                ? ' имя автора отзыва '.$feedback['name'].','
                : '';

            $details = $feedback['productDetails'] ?? [];
            $prompt = sprintf(
                'Это отзыв на товар: %s, от бренда %s, покупатель поставил %s звёзд из 5,%s помоги ответить на него%sне более 300 символов. Если текста отзыва нет или ты его не понял (сленг) - ответь общими словами. Не предлагай: обмен, возврат товара, возмещение средств, обратиться в поддержку.',
                $details['productName'] ?? 'товар',
                $details['brandName'] ?? 'бренд',
                $feedback['productValuation'] ?? '5',
                $authorName,
                $reviewTypeSuffix,
            );

            if (! empty($feedback['text'])) {
                $prompt .= ' Вот текст отзыва: '.$feedback['text'].'.';
            }
            if (! empty($feedback['pros'])) {
                $prompt .= ' Эти достоинства товара указал покупатель: '.$feedback['pros'].'.';
            }
            if (! empty($feedback['cons'])) {
                $prompt .= ' Эти недостатки товара указал покупатель: '.$feedback['cons'].'.';
            }

            $aiRequest = $request->duplicate();
            $aiRequest->merge([
                'prompt' => $prompt,
                'type' => $type,
                'for' => 'feedbacks',
            ]);
        }

        $response = $this->aiTextService->ask($aiRequest);
        $payload = $this->decodeApiResponse($response);

        return response()->json($payload, 200);
    }

    /**
     * @return array{feedbacks: array<int, mixed>, error: ?string}
     */
    private function loadFeedbacks(Request $request, FeedbacksClients $client, int $skip): array
    {
        $response = $this->feedbacksService->getFeedbacksList(
            $this->apiRequestWith($request, [
                'client_id' => $client->id,
                'skip' => $skip,
            ])
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return [
                'feedbacks' => [],
                'error' => $this->apiMessage($payload, 'Не удалось загрузить отзывы'),
            ];
        }

        return [
            'feedbacks' => $payload['data']['feedbacks'] ?? [],
            'error' => null,
        ];
    }
}