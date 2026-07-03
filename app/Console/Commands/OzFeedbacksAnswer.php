<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Traits\ChatGptTrait;
use App\Http\Traits\OzonApiTrait;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Notifications\LimitEndsNotification;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Oz\Feedbacks\FeedbacksClients;
use App\Models\AiRequestLog;
use Illuminate\Support\Facades\Log;

class OzFeedbacksAnswer extends Command
{

    use OzonApiTrait;
    use ChatGptTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriber:oz-feedbacks-answer';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    private $end_limit_notification_num = 30;
    private $limit_ends_notification = true;
    private $review_signature = '';
    private $api_key = '';
    private $client_id = 0;
    private $step = 0;
    private $cabinet = false;
    private $empty_answer = false;
    private $ai_ratings = [];


    private ?SubscribersSubscriptions $subscription = null;
    private $reviews = [];
    private $current_review = [];

    private $products = [];
    private $current_product = [];
    private array $lastAiResponseMeta = [];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $commandStartedAt = microtime(true);
        $this->logCommandEvent('===== OZ FEEDBACKS COMMAND START =====', [
            'started_at' => now()->toDateTimeString(),
        ]);

        $result = Command::SUCCESS;

        try {
            $subscriber_subscriptions = array();
            $subscriptions = SubscribersSubscriptions::where('status', 1)->get();

            if (!$subscriptions || $subscriptions->isEmpty()) {
                $this->logCommandEvent('No active subscriptions found.');
                return $result;
            }

            //Соберем подписчиков с нужным нам тарифом
            foreach ($subscriptions as $subscription) {
                $modelPlan = SubscribersPlans::find($subscription->plan_id);
                if ($modelPlan && in_array('subscriber oz feedbacks', $modelPlan->permissions)) {
                    $subscriber_subscriptions[] = $subscription;
                }
            }

            if (empty($subscriber_subscriptions)) {
                $this->logCommandEvent('No subscriptions with required permissions found.');
                return $result;
            }

            foreach ($subscriber_subscriptions as $subscription) {

                $limit = $subscription->getMonthLimit('feedbacks_gpt_query');
                // Если кончился лимит на ИИ сразу выходим
                if (!$limit) {
                    $this->logCommandEvent('Skip subscription: AI limit exhausted.', [
                        'subscription_id' => $subscription->id,
                        'subscriber_id' => $subscription->subscribers_id,
                    ]);
                    continue;
                }

                $this->subscription = $subscription;

                // У кого включены AI Ответы
                $clients = FeedbacksClients::where([
                    'user_id' => $subscription->getUser()->id,
                    'ai_status' => 1,
                ])->whereJsonLength('ai_ratings', '>', 0)
                    ->get();

                foreach ($clients as $client) {
                    $clientStartedAt = $this->startClientTimer('ai', $client, $subscription->id);
                    $responsesSent = 0;
                    $limitExhausted = false;
                    $fetchFailures = 0;

                    try {
                        $this->step = 0;
                        $this->limit_ends_notification = true;
                        $this->api_key = $client->apikey;
                        $this->client_id = $client->client_id;
                        $this->review_signature = $client->signature;
                        $this->empty_answer = $client->empty_answer;
                        $this->ai_ratings = $client->ai_ratings;

                        $this->cabinet = [
                            'id' => $client['id'],
                            'name' => $client['name'],
                            'user_id' => $client['user_id'] ?? null,
                        ];
                        $this->setUnprocessedReviews();
                        if (!$this->reviews) {
                            // Если нет не отвеченных отзывов - выходим
                            continue;
                        }
                        $this->info('-------------------------------------------------------------------------');
                        $this->info($this->cabinet['name'] . ' - ' . count($this->reviews) . ' отзывов для ответа');
                        $this->logCommandEvent('Reviews found for processing', [
                            'cabinet_name' => $this->cabinet['name'],
                            'reviews_count' => count($this->reviews),
                        ]);

                        $this->setProducts();

                        foreach ($this->reviews as $review) {

                            $limit = $this->subscription->getMonthLimit('feedbacks_gpt_query');
                            if (!$limit) {
                                $limitExhausted = true;
                                break;
                            }

                            if ($limit == $this->end_limit_notification_num && $this->limit_ends_notification) {
                                try {
                                    $this->limit_ends_notification = false;
                                    $subscriber = Subscribers::find($this->subscription->subscribers_id);
                                    $subscriber->user->notify(new LimitEndsNotification([
                                        'limit' => 'feedbacks_gpt_query',
                                    ]));
                                } catch (\Throwable $th) {
                                }
                            }

                            $this->current_review = $review;
                            foreach ($this->products as $product) {
                                if ($review['sku'] == $product['sku']) {
                                    $this->current_product = $product;
                                    break;
                                }
                            }
                            $answer = $this->answerOnReview();
                            // Кончился лимит или не смогли ответить. Пойдём к другому клиенту
                            if (!$answer) {
                                $fetchFailures++;
                                continue 2;
                            }

                            $this->subscription->minusMonthLimit('feedbacks_gpt_query');
                            $responsesSent++;

                            $this->step++;
                            //Допускается 10 запросов в секунду для одного client_id
                            // на все методы
                            if ($this->step >= 8) {
                                $this->step = 0;
                                sleep(1);
                            }
                        }
                    } finally {
                        $this->finishClientTimer('ai', $client, $clientStartedAt, [
                            'responses_sent' => $responsesSent,
                            'limit_exhausted' => $limitExhausted,
                            'fetch_failures' => $fetchFailures,
                            'subscription_id' => $subscription->id,
                            'subscriber_id' => $subscription->subscribers_id,
                        ]);
                    }
                }
            }
        } catch (\Throwable $throwable) {
            $this->logCommandEvent('Command failed', [
                'error_message' => $throwable->getMessage(),
                'error_code' => $throwable->getCode(),
                'error_file' => $throwable->getFile(),
                'error_line' => $throwable->getLine(),
            ]);
            throw $throwable;
        } finally {
            $this->logCommandEvent('===== OZ FEEDBACKS COMMAND END =====', [
                'finished_at' => now()->toDateTimeString(),
                'duration_seconds' => round(microtime(true) - $commandStartedAt, 2),
            ]);
        }

        return $result;
    }

    private function setUnprocessedReviews($reviews = [], $last_id = null)
    {
        $params = ['limit' => 100];
        $filters = ['status' => 'UNPROCESSED'];

        if ($last_id) {
            $params['last_id'] = $last_id;
        }

        $resp = $this->parseApiResponse($this->getReviewList($this->api_key, $this->client_id, $params, $filters), $this->cabinet);

        if ($resp['success']) {

            if ($resp['data']["has_next"]) {
                $last_id = $resp['data']["last_id"];
            }

            $reviews_array = [];
            foreach ($resp['data']['reviews'] as $review) {
                if (!$this->empty_answer) { //отвечать на пустые отзывы
                    if (!empty($review['text'])) {
                        $reviews_array[] = $review;
                    }
                } else {
                    $reviews_array[] = $review;
                }
            }

            $filtered_reviews = array_filter($reviews_array, function ($review) {
                return in_array($review['rating'], $this->ai_ratings);
            });
            $this->info('После фильтрации получено ' . count($filtered_reviews) . ' отзывов');
            // Добавляем отзывы из $filtered_reviews в $reviews
            $reviews = array_merge($reviews, $filtered_reviews);

            if (count($reviews) < 90 && $resp['data']["has_next"]) {
                $this->info("Недостаточно отзывов для обработки, получено: " . count($reviews));
                $this->setUnprocessedReviews($filtered_reviews, $last_id);
                return;
            }
            $this->info("Теперь достаточно отзывов для работы:  " . count($reviews));
            $this->reviews = $reviews;
        } else {
            $this->reviews = false;
        }
    }

    private function answerOnReview()
    {
        $answer = $this->generateAiAnswer();
        if (!$answer)
            return false;

        $answer .= ' ' . $this->review_signature;

        $params = [
            'review_id' => $this->current_review['id'],
            'text' => $answer,
            'mark_review_as_processed' => true
        ];

        $resp = $this->parseApiResponse($this->reviewAnswer($this->api_key, $this->client_id, $params), $this->cabinet);

        $this->logFeedbackAnswerRequest(
            taskType: 'ozon_feedback_answer_ai',
            provider: 'gpt',
            requestPayload: [
                'mode' => 'ai',
                'cabinet_id' => $this->cabinet['id'] ?? null,
                'cabinet_name' => $this->cabinet['name'] ?? null,
                'review_id' => (string) ($this->current_review['id'] ?? ''),
                'sku' => (string) ($this->current_review['sku'] ?? ''),
                'rating' => (int) ($this->current_review['rating'] ?? 0),
                'product_name' => (string) ($this->current_product['name'] ?? ''),
            ],
            success: (bool) $resp['success'],
            statusCode: (int) ($resp['code'] ?? 500),
            errorMessage: $resp['success'] ? null : $this->stringifyResponseError($resp['data'] ?? null),
            responseText: $answer,
            model: (string) ($this->lastAiResponseMeta['model'] ?? 'gpt-4.1'),
            inputTokens: (int) data_get($this->lastAiResponseMeta, 'usage.input_tokens', 0),
            outputTokens: (int) data_get($this->lastAiResponseMeta, 'usage.output_tokens', 0)
        );

        if (!$resp['success']) {
            return false;
        }

        return true;
    }

    private function generateAiAnswer()
    {
        $limit = $this->subscription->getMonthLimit('feedbacks_gpt_query');
        if (!$limit)
            return false;

        if ($limit == $this->end_limit_notification_num) {
            // Отправим уведомление о том, что скоро кончится лимит
            try {
                $subscriber = Subscribers::find($this->subscription->subscribers_id);
                $subscriber->user->notify(new LimitEndsNotification([
                    'limit' => 'feedbacks_gpt_query'
                ]));
            } catch (\Throwable $th) {
            }
        }

        $type = 'копирайтер, задача которого отвечать на отзывы покупателей маркетплейса';

        $prompt = "Это отзыв на товар: " . $this->current_product['name'] . ", покупатель поставил " . $this->current_review['rating'] . " звёзд из 5, помоги ответить на него не более 300 символов. Если текста отзыва нет или ты его не понял (сленг) - ответь общими словами. Не предлагай: обмен, возврат товара, возмещение средств, обратиться в поддержку. Не используй эмодзи. Вот текст отзыва: " . $this->current_review['text'];

        $aiResponse = $this->askToChatGptWithMeta($type, $prompt);
        $this->lastAiResponseMeta = is_array($aiResponse) ? $aiResponse : [];

        if (! ($aiResponse['success'] ?? false)) {
            sleep(1);
            return $this->generateAiAnswer();
        }

        return (string) ($aiResponse['text'] ?? '');
    }

    private function setProducts()
    {
        $sku_list = [];
        foreach ($this->reviews as $review) {
            $sku_list[] = $review["sku"];
        }

        $params = [
            "sku" => $sku_list
        ];

        $resp = $this->parseApiResponse($this->getProductInfo($this->api_key, $this->client_id, $params), $this->cabinet);
        if ($resp['success']) {
            foreach ($resp['data']['items'] as $product) {
                $products[] = [
                    'name' => $product['name'],
                    'sku' => $product['sources'][0]['sku'],
                ];
            }
            $this->products = $products;
        }
    }

    private function logCommandEvent(string $message, array $context = []): void
    {
        Log::channel('oz_feedbacks_command')->info($message, $context);
    }

    private function startClientTimer(string $mode, FeedbacksClients $client, ?int $subscriptionId = null): float
    {
        $context = [
            'mode' => $mode,
            'cabinet_id' => $client->id,
            'cabinet_name' => $client->name,
            'started_at' => now()->toDateTimeString(),
        ];

        if ($subscriptionId !== null) {
            $context['subscription_id'] = $subscriptionId;
        }

        $this->logCommandEvent('=== ' . strtoupper($mode) . ' CLIENT START ===', $context);

        return microtime(true);
    }

    private function finishClientTimer(string $mode, FeedbacksClients $client, float $startedAt, array $metrics = []): void
    {
        $metrics = array_filter($metrics, static function ($value) {
            return $value !== null;
        });

        $durationSeconds = round(microtime(true) - $startedAt, 2);

        $metrics['mode'] = $mode;
        $metrics['cabinet_id'] = $client->id;
        $metrics['cabinet_name'] = $client->name;
        $metrics['finished_at'] = now()->toDateTimeString();
        $metrics['duration_seconds'] = $durationSeconds;
        $metrics['duration_human'] = gmdate('H:i:s', (int) $durationSeconds);

        $this->logCommandEvent('=== ' . strtoupper($mode) . ' CLIENT END ===', $metrics);
    }

    private function logFeedbackAnswerRequest(
        string $taskType,
        string $provider,
        array $requestPayload,
        bool $success,
        int $statusCode,
        ?string $errorMessage,
        ?string $responseText = null,
        ?string $model = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null
    ): void {
        if (! $this->subscription instanceof SubscribersSubscriptions) {
            return;
        }

        try {
            AiRequestLog::create([
                'user_id' => $this->resolveSubscriptionUserId($this->subscription),
                'subscriber_id' => (int) $this->subscription->subscribers_id,
                'task_type' => $taskType,
                'marketplace' => 'ozon',
                'provider' => $provider,
                'model' => $model,
                'request_payload' => $requestPayload,
                'response_text' => $responseText,
                'response_type' => 'text',
                'images_count' => 0,
                'videos_count' => 0,
                'input_tokens' => max(0, (int) ($inputTokens ?? $this->estimateTokensByText(json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''))),
                'output_tokens' => max(0, (int) ($outputTokens ?? $this->estimateTokensByText((string) $responseText))),
                'status_code' => $statusCode,
                'error_message' => $success ? null : $errorMessage,
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $this->logCommandEvent('Feedback answer log write failed', [
                'task_type' => $taskType,
                'provider' => $provider,
                'subscriber_id' => (int) $this->subscription->subscribers_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveSubscriptionUserId(SubscribersSubscriptions $subscription): ?int
    {
        if (method_exists($subscription, 'getUser')) {
            return $subscription->getUser()?->id;
        }

        $subscriber = Subscribers::find($subscription->subscribers_id);

        return $subscriber?->user?->id;
    }

    private function stringifyResponseError(mixed $data): ?string
    {
        if ($data === null) {
            return null;
        }

        if (is_string($data)) {
            return $data;
        }

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? 'Не удалось сериализовать ошибку' : $encoded;
    }
}
