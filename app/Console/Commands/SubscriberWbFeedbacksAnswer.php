<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Traits\ChatGptTrait;
use App\Http\Traits\WBFeedbacksTrait;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\SubscribersPlans;
use App\Notifications\LimitEndsNotification;
use App\Models\Subscribers\Wb\Feedbacks\Review;
use App\Models\AiRequestLog;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Feedbacks\BotResponse;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Notifications\WbCabinetAuthorizationNotification;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksTemplates;
use Illuminate\Support\Facades\Log;

class SubscriberWbFeedbacksAnswer extends Command
{

    use WBFeedbacksTrait;
    use ChatGptTrait;

    private $end_limit_notification_num = 30;
    private $limit_ends_notification = true;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriber:wb-feedbacks-answer';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
        $this->logCommandEvent('===== WB FEEDBACKS COMMAND START =====', [
            'started_at' => now()->toDateTimeString(),
        ]);

        $result = Command::SUCCESS;

        try {
            $subscriberSubscriptions = [];
            $subscriptions = SubscribersSubscriptions::where('status', 1)->get();

            if (!$subscriptions || $subscriptions->isEmpty()) {
                $this->logCommandEvent('No active subscriptions found.');
                return $result;
            }

            foreach ($subscriptions as $subscription) {
                $modelPlan = SubscribersPlans::find($subscription->plan_id);
                if ($modelPlan && in_array('subscriber wb feedbacks', $modelPlan->permissions)) {
                    $subscriberSubscriptions[] = $subscription;
                }
            }

            if (empty($subscriberSubscriptions)) {
                $this->logCommandEvent('No subscriptions with required permissions found.');
                return $result;
            }

            foreach ($subscriberSubscriptions as $subscription) {
                $limit = $subscription->getMonthLimit('feedbacks_gpt_query');
                if (!$limit) {
                    $this->logCommandEvent('Skip subscription: AI limit exhausted.', [
                        'subscription_id' => $subscription->id,
                        'subscriber_id' => $subscription->subscribers_id,
                    ]);
                    continue;
                }

                $clients = FeedbacksClients::where([
                    'subscriber_id' => $subscription->subscribers_id,
                    'ai_status' => 1,
                ])->whereJsonLength('ai_ratings', '>', 0)->get();

                foreach ($clients as $client) {
                    $clientStartedAt = $this->startClientTimer('ai', $client, $subscription->id);
                    $responsesSent = 0;
                    $limitExhausted = false;
                    $authDisabled = false;
                    $fetchFailures = 0;

                    try {
                        $this->limit_ends_notification = true;

                        $cabinet = [
                            'id' => $client['id'],
                            'name' => $client['name'],
                        ];
                        $params = [
                            'take' => 120,
                            'skip' => 0,
                        ];

                        $data = $this->parseApiResponse($this->apiGetFeedbacks($client->apikey, $params), $cabinet);

                        if (!$data['success']) {
                            $fetchFailures++;
                            if ($data['code'] == 401) {
                                try {
                                    $subscriber = Subscribers::find($subscription->subscribers_id);
                                    $subscriber->user->notify(new WbCabinetAuthorizationNotification([
                                        'type' => 'feedbacks',
                                        'cabinet' => $client['name'],
                                    ]));
                                } catch (\Throwable $th) {
                                }
                                $client->ai_status = 0;
                                $client->save();
                                $authDisabled = true;
                            }
                            continue;
                        }

                        $data = $data['data']['data'];

                        switch ($client->review_type) {
                            case 'stih':
                                $prof = 'поэт,';
                                break;

                            default:
                                $prof = 'копирайтер,';
                                break;
                        }

                        $type = $prof . ' задача которого отвечать на отзывы покупателей маркетплейса';
                        foreach ($data['feedbacks'] as $item) {
                            $limit = $subscription->getMonthLimit('feedbacks_gpt_query');
                            if (!$limit) {
                                $limitExhausted = true;
                                continue 2;
                            }

                            if ($limit == $this->end_limit_notification_num && $this->limit_ends_notification) {
                                try {
                                    $this->limit_ends_notification = false;
                                    $subscriber = Subscribers::find($subscription->subscribers_id);
                                    $subscriber->user->notify(new LimitEndsNotification([
                                        'limit' => 'feedbacks_gpt_query',
                                    ]));
                                } catch (\Throwable $th) {
                                }
                            }

                            if (isset($item['childFeedbackId']) && $item['childFeedbackId']) {
                                continue;
                            }

                            $rating = $item['productValuation'];
                            if (!in_array($rating, $client->ai_ratings)) {
                                continue;
                            }

                            if (!empty($client->brands)) {
                                $allow = false;
                                $allowed_brands = explode(',', $client->brands);
                                foreach ($allowed_brands as $value) {
                                    $feedback_brand = strtolower(trim($item['productDetails']['brandName']));
                                    $client_brand = strtolower(trim($value));
                                    if ($feedback_brand == $client_brand) {
                                        $allow = true;
                                        break;
                                    }
                                }
                                if (!$allow) {
                                    continue;
                                }
                            }

                            switch ($client->review_type) {
                                case 'stih':
                                    $review_type = ' в стихах ';
                                    break;

                                default:
                                    $review_type = ' ';
                                    break;
                            }

                            $authorName = isset($item['userName']) && !empty($item['userName'])
                                ? " имя автора отзыва " . $item['userName'] . ","
                                : "";
                            $prompt = "Это отзыв на товар: " . $item['productDetails']['productName'] . ", от бренда " . $item['productDetails']['brandName'] . "," . $authorName . " покупатель поставил " . $rating . " звёзд из 5, помоги ответить на него" . $review_type . "не более 300 символов. Если текста отзыва нет или ты его не понял (сленг) - ответь общими словами. Не предлагай: обмен, возврат товара, возмещение средств, обратиться в поддержку. Не используй эмодзи.";

                            if (isset($item['text']) && !empty($item['text'])) {
                                $prompt .= ' Вот текст отзыва:' . $item['text'];
                            }
                            if (isset($item['pros']) && !empty($item['pros'])) {
                                $prompt .= ' Эти достоинства товара указал покупатель:' . $item['pros'];
                            }
                            if (isset($item['cons']) && !empty($item['cons'])) {
                                $prompt .= ' Эти недостатки товара указал покупатель:' . $item['cons'];
                            }

                            $aiResponse = $this->askToChatGptWithMeta($type, $prompt);

                            if (! ($aiResponse['success'] ?? false)) {
                                continue;
                            }

                            $answer = (string) ($aiResponse['text'] ?? '');

                            $review = Review::updateOrCreate(
                                ['id' => $item['id']],
                                [
                                    'cabinet_id' => $client->id,
                                    'product_id' => $item['productDetails']['nmId'],
                                    'rating' => $item['productValuation'],
                                    'content' => $item['text'] ?? '',
                                    'pros' => $item['pros'] ?? '',
                                    'cons' => $item['cons'] ?? '',
                                    'bables' => $item['bables'] ?? [],
                                    'photo_links' => $item['photoLinks'] ?? [],
                                    'matching_size' => $item['matchingSize'] ?? '',
                                    'color' => $item['color'] ?? '',
                                    'subject_name' => $item['subjectName'] ?? '',
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]
                            );

                            BotResponse::create([
                                'review_id' => $review->id,
                                'response_text' => $answer,
                                'is_ai_response' => true,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                            $params = [
                                'id' => $item['id'],
                                'text' => $answer,
                            ];

                            $resp = $this->parseApiResponse($this->apiPostAnswer($client->apikey, $params), $cabinet);

                            $this->logFeedbackAnswerRequest(
                                subscription: $subscription,
                                taskType: 'wb_feedback_answer_ai',
                                provider: 'gpt',
                                requestPayload: [
                                    'mode' => 'ai',
                                    'cabinet_id' => $client->id,
                                    'cabinet_name' => $client->name,
                                    'review_id' => (string) ($item['id'] ?? ''),
                                    'product_id' => (int) ($item['productDetails']['nmId'] ?? 0),
                                    'rating' => (int) ($item['productValuation'] ?? 0),
                                    'prompt' => $prompt,
                                ],
                                success: (bool) $resp['success'],
                                statusCode: (int) ($resp['code'] ?? 500),
                                errorMessage: $resp['success'] ? null : $this->stringifyResponseError($resp['data'] ?? null),
                                responseText: $answer,
                                model: (string) ($aiResponse['model'] ?? 'gpt-4.1'),
                                inputTokens: (int) data_get($aiResponse, 'usage.input_tokens', $this->estimateTokensByText($type . ' ' . $prompt)),
                                outputTokens: (int) data_get($aiResponse, 'usage.output_tokens', $this->estimateTokensByText($answer))
                            );

                            if (!$resp['success']) {
                                if ($resp['code'] == 401) {
                                    try {
                                        $subscriber = Subscribers::find($subscription->subscribers_id);
                                        $subscriber->user->notify(new WbCabinetAuthorizationNotification([
                                            'type' => 'feedbacks_cant_answer',
                                            'cabinet' => $client['name'],
                                        ]));
                                    } catch (\Throwable $th) {
                                    }
                                    $client->ai_status = 0;
                                    $client->save();
                                    $authDisabled = true;
                                }
                                break;
                            }

                            $subscription->minusMonthLimit('feedbacks_gpt_query');
                            $responsesSent++;

                            sleep(1);
                        }
                    } finally {
                        $this->finishClientTimer('ai', $client, $clientStartedAt, [
                            'responses_sent' => $responsesSent,
                            'limit_exhausted' => $limitExhausted,
                            'fetch_failures' => $fetchFailures,
                            'auth_disabled' => $authDisabled,
                            'subscription_id' => $subscription->id,
                            'subscriber_id' => $subscription->subscribers_id,
                        ]);
                    }
                }
            }

            $this->logCommandEvent('Waiting before template replies', ['seconds' => 60]);
            sleep(60);
            $this->logCommandEvent('Starting template replies');

            foreach ($subscriberSubscriptions as $subscription) {
                $clients = FeedbacksClients::where([
                    'subscriber_id' => $subscription->subscribers_id,
                    'bot_status' => 1,
                ])->get();

                foreach ($clients as $client) {
                    $clientStartedAt = $this->startClientTimer('template', $client, $subscription->id);
                    $responsesSent = 0;
                    $fetchFailures = 0;
                    $authDisabled = false;

                    try {
                        $cabinet = [
                            'id' => $client['id'],
                            'name' => $client['name'],
                        ];
                        $params = [
                            'take' => 120,
                            'skip' => 0,
                        ];

                        $data = $this->parseApiResponse($this->apiGetFeedbacks($client->apikey, $params), $cabinet);

                        if (!$data['success']) {
                            $fetchFailures++;
                            if ($data['code'] == 401) {
                                try {
                                    $subscriber = Subscribers::find($subscription->subscribers_id);
                                    $subscriber->user->notify(new WbCabinetAuthorizationNotification([
                                        'type' => 'feedbacks',
                                        'cabinet' => $client['name'],
                                    ]));
                                } catch (\Throwable $th) {
                                }
                                $client->bot_status = 0;
                                $client->save();
                                $authDisabled = true;
                            }
                            continue;
                        }

                        $templates = FeedbacksTemplates::select('text', 'rating')
                            ->where('client_id', $client->id)
                            ->inRandomOrder()
                            ->get()
                            ->toArray();

                        $data = $data['data']['data'];
                        foreach ($data['feedbacks'] as $item) {
                            if (isset($item['childFeedbackId']) && $item['childFeedbackId']) {
                                continue;
                            }

                            $rating = (int) $item['productValuation'];
                            $templates_filtered = array_filter($templates, function ($value) use ($rating) {
                                return ($rating >= (int) $value['rating'][0] && $rating <= (int) $value['rating'][1]);
                            });
                            if (empty($templates_filtered)) {
                                continue;
                            }

                            if (!empty($client->brands)) {
                                $allow = false;
                                $allowed_brands = explode(',', $client->brands);
                                foreach ($allowed_brands as $value) {
                                    $feedback_brand = strtolower(trim($item['productDetails']['brandName']));
                                    $client_brand = strtolower(trim($value));
                                    if ($feedback_brand == $client_brand) {
                                        $allow = true;
                                        break;
                                    }
                                }
                                if (!$allow) {
                                    continue;
                                }
                            }

                            $template = $templates_filtered[array_rand($templates_filtered, 1)];

                            $product_name = $item['productDetails']['productName'];
                            $brand = $item['productDetails']['brandName'];
                            $star = $item['productValuation'];
                            $authorName = isset($item['userName']) && !empty($item['userName'])
                                ? $item['userName']
                                : "";
                            $answer = str_replace(['{username}', '{name}', '{star}', '{brand}'], [$authorName, $product_name, $star, $brand], $template['text']);

                            $review = Review::updateOrCreate(
                                ['id' => $item['id']],
                                [
                                    'cabinet_id' => $client->id,
                                    'product_id' => $item['productDetails']['nmId'],
                                    'rating' => $item['productValuation'],
                                    'content' => $item['text'] ?? '',
                                    'pros' => $item['pros'] ?? '',
                                    'cons' => $item['cons'] ?? '',
                                    'bables' => $item['bables'] ?? [],
                                    'photo_links' => $item['photoLinks'] ?? [],
                                    'matching_size' => $item['matchingSize'] ?? '',
                                    'color' => $item['color'] ?? '',
                                    'subject_name' => $item['subjectName'] ?? '',
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]
                            );

                            BotResponse::create([
                                'review_id' => $review->id,
                                'response_text' => $answer,
                                'is_ai_response' => false,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);

                            $params = [
                                'id' => $item['id'],
                                'text' => $answer,
                            ];

                            $resp = $this->parseApiResponse($this->apiPostAnswer($client->apikey, $params), $cabinet);

                            $this->logFeedbackAnswerRequest(
                                subscription: $subscription,
                                taskType: 'wb_feedback_answer_template',
                                provider: 'template',
                                requestPayload: [
                                    'mode' => 'template',
                                    'cabinet_id' => $client->id,
                                    'cabinet_name' => $client->name,
                                    'review_id' => (string) ($item['id'] ?? ''),
                                    'product_id' => (int) ($item['productDetails']['nmId'] ?? 0),
                                    'rating' => (int) ($item['productValuation'] ?? 0),
                                ],
                                success: (bool) $resp['success'],
                                statusCode: (int) ($resp['code'] ?? 500),
                                errorMessage: $resp['success'] ? null : $this->stringifyResponseError($resp['data'] ?? null),
                                responseText: $answer,
                                model: 'template',
                                inputTokens: $this->estimateTokensByText(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
                                outputTokens: $this->estimateTokensByText($answer)
                            );

                            sleep(1);

                            if (!$resp['success']) {
                                $fetchFailures++;
                                continue;
                            }

                            $responsesSent++;
                        }
                    } finally {
                        $this->finishClientTimer('template', $client, $clientStartedAt, [
                            'responses_sent' => $responsesSent,
                            'fetch_failures' => $fetchFailures,
                            'auth_disabled' => $authDisabled,
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
            $this->logCommandEvent('===== WB FEEDBACKS COMMAND END =====', [
                'finished_at' => now()->toDateTimeString(),
                'duration_seconds' => round(microtime(true) - $commandStartedAt, 2),
            ]);
        }

        return $result;
    }

    private function logCommandEvent(string $message, array $context = []): void
    {
        Log::channel('wb_feedbacks_command')->info($message, $context);
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
        SubscribersSubscriptions $subscription,
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
        try {
            AiRequestLog::create([
                'user_id' => $this->resolveSubscriptionUserId($subscription),
                'subscriber_id' => (int) $subscription->subscribers_id,
                'task_type' => $taskType,
                'marketplace' => 'wb',
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
                'subscriber_id' => (int) $subscription->subscribers_id,
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
