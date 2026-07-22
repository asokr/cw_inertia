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

        $filters = $this->parseFeedbackFilters($request);
        $feedbacksPayload = $this->loadFeedbacks($request, $client, 0, $filters);
        $aiPayload = $this->decodeApiResponse(
            $this->clientsService->getAiData(
                $this->apiRequestWith($request, ['client_id' => $client->id])
            )
        );

        $subscription = SubscribersSubscriptions::query()
            ->where('subscribers_id', $request->user()->subscriber?->id)
            ->where('status', 1)
            ->first();

        $allFeedbacks = $feedbacksPayload['feedbacks'];
        $total = count($allFeedbacks);
        $perPage = $filters['per_page'];
        $page = $filters['page'];

        if ($perPage === 0) {
            $pageItems = $allFeedbacks;
            $pageCount = 1;
            $page = 1;
        } else {
            $pageCount = max(1, (int) ceil($total / $perPage));
            $page = min($page, $pageCount);
            $offset = ($page - 1) * $perPage;
            $pageItems = array_slice($allFeedbacks, $offset, $perPage);
        }

        $brands = $this->parseBrandsList($client->brands);

        return Inertia::render('Subscriber/Wb/Feedbacks/Client/Show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'brands' => $client->brands ?? '',
            ],
            'feedbacks' => array_values($pageItems),
            'feedbacksError' => $feedbacksPayload['error'],
            'feedbacksMeta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'page_count' => $pageCount,
                'count_from_wb' => $feedbacksPayload['countFromWb'],
                'wb_count_unanswered' => $feedbacksPayload['wbCountUnanswered'],
                'pages_fetched' => $feedbacksPayload['pagesFetched'],
                'truncated' => $feedbacksPayload['truncated'],
                'brand_filter_active' => $brands !== [],
                'brands' => $brands,
                'skipped_by_brand' => $feedbacksPayload['skippedByBrand'],
            ],
            'filters' => [
                'nmId' => $filters['nmId'],
                'ratings' => $filters['ratings'],
                'page' => $page,
                'per_page' => $perPage,
            ],
            'aiSettings' => ($aiPayload['success'] ?? false) ? ($aiPayload['data'] ?? null) : null,
            'aiLimit' => ToolLimits::monthLimitValue($request->user(), $subscription, 'feedbacks_gpt_query'),
            'ratingType' => ($aiPayload['data']['review_type'] ?? null),
        ]);
    }

    public function answered(Request $request, FeedbacksClients $client): JsonResponse
    {
        $this->ensureClientOwnership($client);

        // Merge onto the live request so service auth + input bags stay consistent.
        $request->merge([
            'cabinet_id' => $client->id,
        ]);

        return $this->statsService->answeredReviews($request);
    }

    public function refresh(Request $request, FeedbacksClients $client): RedirectResponse
    {
        $this->ensureClientOwnership($client);

        $filters = $this->parseFeedbackFilters($request);
        $feedbacksPayload = $this->loadFeedbacks($request, $client, 0, $filters);

        $query = $this->filtersToQuery($filters);

        if ($feedbacksPayload['error']) {
            return redirect()
                ->route('subscriber.wb.feedbacks.clients.show', array_merge(['client' => $client], $query))
                ->with('error', $feedbacksPayload['error']);
        }

        return redirect()
            ->route('subscriber.wb.feedbacks.clients.show', array_merge(['client' => $client], $query))
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
     * @return array{
     *     nmId: ?int,
     *     ratings: list<int>,
     *     page: int,
     *     per_page: int
     * }
     */
    private function parseFeedbackFilters(Request $request): array
    {
        $nmIdRaw = $request->input('nmId');
        $nmId = null;
        if ($nmIdRaw !== null && $nmIdRaw !== '' && is_numeric($nmIdRaw)) {
            $nmId = max(1, (int) $nmIdRaw);
        }

        $ratings = collect($request->input('ratings', []))
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v >= 1 && $v <= 5)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        $page = max(1, (int) $request->input('page', 1));
        $perPage = (int) $request->input('per_page', 10);
        if (! in_array($perPage, [0, 10, 20, 50], true)) {
            $perPage = 10;
        }

        return [
            'nmId' => $nmId,
            'ratings' => $ratings,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param  array{nmId: ?int, ratings: list<int>, page: int, per_page: int}  $filters
     * @return array<string, mixed>
     */
    private function filtersToQuery(array $filters): array
    {
        $query = [
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
        ];

        if ($filters['nmId']) {
            $query['nmId'] = $filters['nmId'];
        }
        if ($filters['ratings'] !== []) {
            $query['ratings'] = $filters['ratings'];
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function parseBrandsList(?string $brands): array
    {
        if ($brands === null || trim($brands) === '') {
            return [];
        }

        return collect(explode(',', $brands))
            ->map(fn ($b) => trim((string) $b))
            ->filter(fn ($b) => $b !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array{nmId: ?int, ratings: list<int>, page: int, per_page: int}  $filters
     * @return array{
     *     feedbacks: array<int, mixed>,
     *     error: ?string,
     *     countFromWb: int,
     *     wbCountUnanswered: ?int,
     *     pagesFetched: int,
     *     truncated: bool,
     *     skippedByBrand: int
     * }
     */
    private function loadFeedbacks(Request $request, FeedbacksClients $client, int $skip, array $filters = []): array
    {
        $params = [
            'client_id' => $client->id,
            'skip' => $skip,
        ];

        if (! empty($filters['nmId'])) {
            $params['nmId'] = $filters['nmId'];
        }
        if (! empty($filters['ratings'])) {
            $params['ratings'] = $filters['ratings'];
        }

        $response = $this->feedbacksService->getFeedbacksList(
            $this->apiRequestWith($request, $params)
        );
        $payload = $this->decodeApiResponse($response);

        if (($payload['success'] ?? false) !== true) {
            return [
                'feedbacks' => [],
                'error' => $this->apiMessage($payload, 'Не удалось загрузить отзывы'),
                'countFromWb' => 0,
                'wbCountUnanswered' => null,
                'pagesFetched' => 0,
                'truncated' => false,
                'skippedByBrand' => 0,
            ];
        }

        return [
            'feedbacks' => $payload['data']['feedbacks'] ?? [],
            'error' => null,
            'countFromWb' => (int) ($payload['data']['countFromWb'] ?? 0),
            'wbCountUnanswered' => isset($payload['data']['wbCountUnanswered'])
                ? (int) $payload['data']['wbCountUnanswered']
                : null,
            'pagesFetched' => (int) ($payload['data']['pagesFetched'] ?? 0),
            'truncated' => (bool) ($payload['data']['truncated'] ?? false),
            'skippedByBrand' => (int) ($payload['data']['filters']['skipped_by_brand'] ?? 0),
        ];
    }
}