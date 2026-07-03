<?php

namespace App\Http\Controllers\Web\Subscriber\Oz\Feedbacks;

use App\Http\Controllers\Api\Subscriber\Ai\AiController as ApiAiController;
use App\Http\Controllers\Api\Subscriber\Ozon\Feedbacks\FeedbacksClientsController as ApiFeedbacksClientsController;
use App\Http\Controllers\Api\Subscriber\Ozon\Feedbacks\FeedbacksController as ApiFeedbacksController;
use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresOzonFeedbacksCabinetOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Http\Requests\Web\Subscriber\SendFeedbackRequest;
use App\Http\Requests\Web\Subscriber\UpdateOzAiDataRequest;
use App\Models\Subscribers\Oz\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\SubscribersSubscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedbacksController extends SubscriberToolController
{
    use EnsuresOzonFeedbacksCabinetOwnership;

    public function __construct(
        private readonly ApiFeedbacksController $apiFeedbacksController,
        private readonly ApiFeedbacksClientsController $apiClientsController,
        private readonly ApiAiController $apiAiController,
    ) {
    }

    public function show(Request $request, FeedbacksClients $cabinet): Response
    {
        $this->ensureCabinetOwnership($cabinet);

        $reviewsPayload = $this->loadReviews($request, $cabinet);
        $aiPayload = $this->decodeApiResponse(
            $this->apiClientsController->getAiData($request, (string) $cabinet->id)
        );
        $countPayload = $this->decodeApiResponse(
            $this->apiFeedbacksController->countFeedbacks(
                $this->apiRequestWith($request, ['cabinet_id' => $cabinet->id])
            )
        );

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $request->user()->subscriber?->id)
            ->where('status', 1)
            ->first();

        return Inertia::render('Subscriber/Oz/Feedbacks/Cabinet/Show', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
            ],
            'reviews' => $reviewsPayload['reviews'],
            'reviewsError' => $reviewsPayload['error'],
            'lastId' => $reviewsPayload['last_id'],
            'unprocessedCount' => ($countPayload['success'] ?? false) ? ($countPayload['data'] ?? null) : null,
            'aiSettings' => ($aiPayload['success'] ?? false) ? ($aiPayload['data'] ?? null) : null,
            'aiLimit' => $subscription ? (int) ($subscription->getMonthLimit('feedbacks_gpt_query') ?: 0) : 0,
        ]);
    }

    public function refresh(Request $request, FeedbacksClients $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $reviewsPayload = $this->loadReviews($request, $cabinet);

        if ($reviewsPayload['error']) {
            return redirect()
                ->route('subscriber.oz.feedbacks.cabinets.show', $cabinet)
                ->with('error', $reviewsPayload['error']);
        }

        return redirect()
            ->route('subscriber.oz.feedbacks.cabinets.show', $cabinet)
            ->with('success', 'Данные обновлены');
    }

    public function send(SendFeedbackRequest $request, FeedbacksClients $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->apiFeedbacksController->answerFeedback(
            $request->duplicate(null, [
                'cabinet_id' => $cabinet->id,
                'id' => $request->validated('id'),
                'text' => $request->validated('text'),
            ])
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось отправить ответ'));
        }

        return redirect()
            ->route('subscriber.oz.feedbacks.cabinets.show', $cabinet)
            ->with('success', $this->apiMessage($payload, 'Ответ отправлен'));
    }

    public function updateAi(UpdateOzAiDataRequest $request, FeedbacksClients $cabinet): RedirectResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $response = $this->apiClientsController->updateAiData(
            $request->duplicate(null, [
                'cabinet_id' => $cabinet->id,
                'status' => $request->validated('status'),
                'ratings' => $request->validated('ratings'),
                'empty_answer' => $request->boolean('empty_answer'),
                'signature' => $request->input('signature'),
            ])
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return back()->with('error', $this->apiMessage($payload, 'Не удалось сохранить настройки ИИ'));
        }

        return back()->with('success', $this->apiMessage($payload, 'Настройки ИИ сохранены'));
    }

    public function generateAi(Request $request, FeedbacksClients $cabinet): JsonResponse
    {
        $this->ensureCabinetOwnership($cabinet);

        $validated = $request->validate([
            'feedback' => ['nullable', 'array'],
            'prompt' => ['nullable', 'string', 'min:10', 'max:4000'],
            'type' => ['nullable', 'string', 'max:500'],
        ]);

        if (! empty($validated['prompt'])) {
            $aiRequest = $request->duplicate(null, [
                'prompt' => $validated['prompt'],
                'type' => $validated['type'] ?? 'копирайтер, задача которого отвечать на отзывы покупателей маркетплейса',
                'for' => 'feedbacks',
            ]);
        } else {
            $feedback = $validated['feedback'] ?? [];
            $prompt = sprintf(
                'Это отзыв на товар: %s, покупатель поставил %s звёзд из 5, помоги ответить на него не более 300 символов. Если текста отзыва нет или ты его не понял (сленг) - ответь общими словами. Не предлагай: обмен, возврат товара, возмещение средств, обратиться в поддержку. Не используй эмодзи. Вот текст отзыва: %s',
                $feedback['product_name'] ?? 'товар',
                $feedback['rating'] ?? '5',
                $feedback['text'] ?? '',
            );

            $aiRequest = $request->duplicate(null, [
                'prompt' => $prompt,
                'type' => 'копирайтер, задача которого отвечать на отзывы покупателей маркетплейса',
                'for' => 'feedbacks',
            ]);
        }

        $response = $this->apiAiController->ask($aiRequest);
        $payload = $this->decodeApiResponse($response);

        return response()->json($payload, 200);
    }

    /**
     * @return array{reviews: array<int, mixed>, last_id: ?string, error: ?string}
     */
    private function loadReviews(Request $request, FeedbacksClients $cabinet, ?string $lastId = null): array
    {
        $params = ['cabinet_id' => $cabinet->id];
        if ($lastId) {
            $params['last_id'] = $lastId;
        }

        $response = $this->apiFeedbacksController->getFeedbacksList(
            $this->apiRequestWith($request, $params)
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return [
                'reviews' => [],
                'last_id' => null,
                'error' => $this->apiMessage($payload, 'Не удалось загрузить отзывы'),
            ];
        }

        return [
            'reviews' => $payload['data']['reviews'] ?? [],
            'last_id' => $payload['data']['last_id'] ?? null,
            'error' => null,
        ];
    }
}