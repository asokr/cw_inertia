<?php

namespace App\Services\Wb\AiCabinetAnalyzer;

use App\Enums\AiTaskType;
use App\Models\AiRequestLog;
use App\Models\Subscribers\Subscribers;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerTemplate;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerReport;
use App\Services\Gemini\GeminiApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class AiCabinetAnalyzerAiAnalysisService
{
    private const MAX_BATCH_JSON_CHARS = 140000;
    private const GPT_FALLBACK_MODEL = 'gpt-4.1';
    private const MAX_OUTPUT_TOKENS_JSON = 4096;
    private const MAX_OUTPUT_TOKENS_MARKDOWN = 12000;

    // Context for central AI request logging (populated when called from the Job)
    protected ?int $logUserId = null;
    protected ?int $logSubscriberId = null;

    public function __construct(
        private readonly GeminiApiClient $geminiApiClient,
    ) {}

    public function run(AiCabinetAnalyzerReport $report, AiCabinetAnalyzerTemplate $template, string $requestedModel, ?int $userId = null, ?int $subscriberId = null): array
    {
        if ((string) $report->status !== AiCabinetAnalyzerReport::STATUS_DONE) {
            throw new RuntimeException('ИИ-анализ можно запускать только для готового отчёта.');
        }

        $this->logUserId = $userId;
        $this->logSubscriberId = $subscriberId;

        $normalizedDataset = $this->normalizeDataset((array) ($report->result_json ?? []));
        if (empty($normalizedDataset['items']) && empty($normalizedDataset['campaigns'])) {
            throw new RuntimeException('В отчёте нет данных для ИИ-анализа.');
        }

        $model = $this->resolveModel($requestedModel);
        $batches = $this->buildBatches($normalizedDataset);

        $isMarkdown = ((string) ($template->response_format ?? 'json')) === 'markdown';
        $fieldInstructions = $this->getFieldInstructions();

        $batchResults = [];
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalTokens = 0;
        $usedProviders = [];
        $usedModels = [];

        foreach ($batches as $index => $batch) {
            $batchResult = $this->runBatchAnalysis(
                systemPrompt: (string) $template->system_prompt,
                model: $model,
                batch: $batch,
                batchNumber: $index + 1,
                totalBatches: count($batches),
                isMarkdown: $isMarkdown,
                fieldInstructions: $fieldInstructions,
            );

            $batchResults[] = $batchResult;
            $totalInputTokens += (int) $batchResult['input_tokens'];
            $totalOutputTokens += (int) $batchResult['output_tokens'];
            $totalTokens += (int) $batchResult['total_tokens'];
            $usedProviders[] = (string) ($batchResult['provider'] ?? 'gemini');
            $usedModels[] = (string) ($batchResult['model'] ?? $model);
        }

        if ($isMarkdown) {
            $finalMarkdown = (string) ($batchResults[0]['markdown'] ?? '');
            if (count($batchResults) > 1) {
                $finalResult = $this->runFinalAggregation(
                    systemPrompt: (string) $template->system_prompt,
                    model: $model,
                    batchResults: $batchResults,
                    datasetMeta: (array) ($normalizedDataset['meta'] ?? []),
                    isMarkdown: true,
                    fieldInstructions: $fieldInstructions,
                );
                $finalMarkdown = (string) ($finalResult['markdown'] ?? '');
                // tokens etc handled in final
                $finalInputTokens = (int) ($finalResult['input_tokens'] ?? 0);
                $finalOutputTokens = (int) ($finalResult['output_tokens'] ?? 0);
                $finalTotalTokens = (int) ($finalResult['total_tokens'] ?? 0);

                $totalInputTokens += $finalInputTokens;
                $totalOutputTokens += $finalOutputTokens;
                $totalTokens += $finalTotalTokens;
                $usedProviders[] = (string) ($finalResult['provider'] ?? 'gemini');
                $usedModels[] = (string) ($finalResult['model'] ?? $model);
            }

            $usedProviders = array_values(array_unique(array_filter($usedProviders)));
            $usedModels = array_values(array_unique(array_filter($usedModels)));
            $finalModel = $usedModels[0] ?? $model;

            return [
                'analysis_markdown' => $finalMarkdown,
                'analysis_text' => null,
                'analysis_json' => [],
                'input_tokens' => $totalInputTokens,
                'output_tokens' => $totalOutputTokens,
                'total_tokens' => $totalTokens,
                'max_output_tokens' => $this->resolveMaxOutputTokens(true),
                'model' => $finalModel,
            ];
        }

        // JSON path (unchanged behavior)
        $finalText = (string) ($batchResults[0]['text'] ?? '');
        $finalJson = (array) ($batchResults[0]['json'] ?? []);

        if (count($batchResults) > 1) {
            $finalResult = $this->runFinalAggregation(
                systemPrompt: (string) $template->system_prompt,
                model: $model,
                batchResults: $batchResults,
                datasetMeta: (array) ($normalizedDataset['meta'] ?? []),
                isMarkdown: false,
                fieldInstructions: $fieldInstructions,
            );

            $finalText = (string) ($finalResult['text'] ?? '');
            $finalJson = (array) ($finalResult['json'] ?? []);
            $finalInputTokens = (int) ($finalResult['input_tokens'] ?? 0);
            $finalOutputTokens = (int) ($finalResult['output_tokens'] ?? 0);
            $finalTotalTokens = (int) ($finalResult['total_tokens'] ?? 0);

            $totalInputTokens += $finalInputTokens;
            $totalOutputTokens += $finalOutputTokens;
            $totalTokens += $finalTotalTokens;
            $usedProviders[] = (string) ($finalResult['provider'] ?? 'gemini');
            $usedModels[] = (string) ($finalResult['model'] ?? $model);
        }

        $usedProviders = array_values(array_unique(array_filter($usedProviders)));
        $usedModels = array_values(array_unique(array_filter($usedModels)));
        $finalModel = $usedModels[0] ?? $model;

        $finalJson['meta'] = array_merge((array) ($finalJson['meta'] ?? []), [
            'batches_count' => count($batches),
            'dataset_items_count' => count((array) ($normalizedDataset['items'] ?? [])),
            'dataset_campaigns_count' => count((array) ($normalizedDataset['campaigns'] ?? [])),
            'provider' => count($usedProviders) === 1 ? $usedProviders[0] : 'mixed',
            'providers' => $usedProviders,
            'models' => $usedModels,
        ]);

        return [
            'analysis_text' => $finalText,
            'analysis_json' => $finalJson,
            'input_tokens' => $totalInputTokens,
            'output_tokens' => $totalOutputTokens,
            'total_tokens' => $totalTokens,
            'max_output_tokens' => $this->resolveMaxOutputTokens(false),
            'model' => $finalModel,
        ];
    }

    private function normalizeDataset(array $dataset): array
    {
        $meta = [
            'generated_at' => data_get($dataset, 'meta.generated_at'),
            'period' => (array) data_get($dataset, 'meta.period', []),
            'totals' => (array) data_get($dataset, 'meta.totals', []),
        ];

        $campaigns = [];
        foreach ((array) ($dataset['campaigns'] ?? []) as $campaign) {
            $campaigns[] = [
                'advert_id' => (int) ($campaign['advert_id'] ?? 0),
                'nmids' => array_values(array_map('intval', (array) ($campaign['nmids'] ?? []))),
                'stats' => [
                    'clicks' => (int) data_get($campaign, 'stats.clicks', 0),
                    'views' => (int) data_get($campaign, 'stats.views', 0),
                    'spend' => (float) data_get($campaign, 'stats.spend', 0),
                    'orders' => (int) data_get($campaign, 'stats.orders', 0),
                ],
            ];
        }

        $items = [];
        foreach ((array) ($dataset['items'] ?? []) as $item) {
            $items[] = [
                'nmid' => (int) ($item['nmid'] ?? 0),
                'advert_ids' => array_values(array_map('intval', (array) ($item['advert_ids'] ?? []))),
                'campaigns_count' => (int) ($item['campaigns_count'] ?? 0),
                'clicks' => (int) ($item['clicks'] ?? 0),
                'views' => (int) ($item['views'] ?? 0),
                'spend' => (float) ($item['spend'] ?? 0),
                'orders' => (int) ($item['orders'] ?? 0),
                'ctr' => (float) ($item['ctr'] ?? 0),
                'cpc' => (float) ($item['cpc'] ?? 0),
                'cr' => (float) ($item['cr'] ?? 0),
                'funnel' => [
                    'open_count' => (int) data_get($item, 'funnel.open_count', 0),
                    'cart_count' => (int) data_get($item, 'funnel.cart_count', 0),
                    'order_count' => (int) data_get($item, 'funnel.order_count', 0),
                    'order_sum' => (float) data_get($item, 'funnel.order_sum', 0),
                    'buyout_count' => (int) data_get($item, 'funnel.buyout_count', 0),
                    'buyout_sum' => (float) data_get($item, 'funnel.buyout_sum', 0),
                    'cancel_count' => (int) data_get($item, 'funnel.cancel_count', 0),
                    'cancel_sum' => (float) data_get($item, 'funnel.cancel_sum', 0),
                    'avg_price' => (float) data_get($item, 'funnel.avg_price', 0),
                    'share_order_percent' => (float) data_get($item, 'funnel.share_order_percent', 0),
                    'conversions' => (array) data_get($item, 'funnel.conversions', []),
                ],
                'ads_vs_funnel' => [
                    'orders_gap' => (int) data_get($item, 'ads_vs_funnel.orders_gap', 0),
                    'orders_ratio_ads_to_funnel' => data_get($item, 'ads_vs_funnel.orders_ratio_ads_to_funnel'),
                ],
                'reviews' => [
                    'pros' => array_values((array) data_get($item, 'reviews.pros', [])),
                    'cons' => array_values((array) data_get($item, 'reviews.cons', [])),
                    'bables' => array_values((array) data_get($item, 'reviews.bables', [])),
                    'rating_distribution' => (array) data_get($item, 'reviews.rating_distribution', []),
                    'average_rating' => (float) data_get($item, 'reviews.average_rating', 0),
                    'photo_stats' => [
                        'with_photos' => (int) data_get($item, 'reviews.photo_stats.with_photos', 0),
                        'without_photos' => (int) data_get($item, 'reviews.photo_stats.without_photos', 0),
                    ],
                ],
            ];
        }

        $feedbacks = [];
        foreach ((array) ($dataset['feedbacks'] ?? []) as $feedback) {
            if (!is_array($feedback)) {
                continue;
            }

            $normalized = $this->normalizeFeedbackRow($feedback);
            if ($normalized !== null) {
                $feedbacks[] = $normalized;
            }
        }

        return [
            'meta' => $meta,
            'campaigns' => $campaigns,
            'items' => $items,
            'feedbacks' => $feedbacks,
        ];
    }

    private function buildBatches(array $dataset): array
    {
        $items = (array) ($dataset['items'] ?? []);
        $allFeedbacks = (array) ($dataset['feedbacks'] ?? []);
        if ($items === []) {
            return [[
                'meta' => (array) ($dataset['meta'] ?? []),
                'campaigns' => (array) ($dataset['campaigns'] ?? []),
                'items' => [],
                'feedbacks' => [],
            ]];
        }

        $campaignsById = [];
        foreach ((array) ($dataset['campaigns'] ?? []) as $campaign) {
            $campaignId = (int) ($campaign['advert_id'] ?? 0);
            if ($campaignId > 0) {
                $campaignsById[$campaignId] = $campaign;
            }
        }

        $batches = [];
        $currentItems = [];

        foreach ($items as $item) {
            $candidateItems = array_merge($currentItems, [$item]);
            $candidateCampaigns = $this->extractCampaignsForItems($candidateItems, $campaignsById);

            $candidate = [
                'meta' => (array) ($dataset['meta'] ?? []),
                'campaigns' => $candidateCampaigns,
                'items' => $candidateItems,
                'feedbacks' => $this->extractFeedbacksForItems($allFeedbacks, $candidateItems),
            ];

            if ($this->estimatePayloadSize($candidate) > self::MAX_BATCH_JSON_CHARS && $currentItems !== []) {
                $batches[] = [
                    'meta' => (array) ($dataset['meta'] ?? []),
                    'campaigns' => $this->extractCampaignsForItems($currentItems, $campaignsById),
                    'items' => $currentItems,
                    'feedbacks' => $this->extractFeedbacksForItems($allFeedbacks, $currentItems),
                ];

                $currentItems = [$item];
                continue;
            }

            $currentItems = $candidateItems;
        }

        if ($currentItems !== []) {
            $batches[] = [
                'meta' => (array) ($dataset['meta'] ?? []),
                'campaigns' => $this->extractCampaignsForItems($currentItems, $campaignsById),
                'items' => $currentItems,
                'feedbacks' => $this->extractFeedbacksForItems($allFeedbacks, $currentItems),
            ];
        }

        return $batches;
    }

    /**
     * @param  array<int, array<string, mixed>>  $feedbacks
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function extractFeedbacksForItems(array $feedbacks, array $items): array
    {
        $nmids = [];
        foreach ($items as $item) {
            $nmid = (int) ($item['nmid'] ?? 0);
            if ($nmid > 0) {
                $nmids[$nmid] = true;
            }
        }

        if ($nmids === []) {
            return [];
        }

        $result = [];
        foreach ($feedbacks as $feedback) {
            $nmid = (int) data_get($feedback, 'productDetails.nmId', data_get($feedback, 'productDetails.nmID', 0));
            if ($nmid > 0 && isset($nmids[$nmid])) {
                $result[] = $feedback;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $feedback
     * @return array<string, mixed>|null
     */
    private function normalizeFeedbackRow(array $feedback): ?array
    {
        $id = trim((string) ($feedback['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        $photoLinks = (array) ($feedback['photoLinks'] ?? []);

        return [
            'id' => $id,
            'text' => (string) ($feedback['text'] ?? ''),
            'pros' => (string) ($feedback['pros'] ?? ''),
            'cons' => (string) ($feedback['cons'] ?? ''),
            'productValuation' => (int) ($feedback['productValuation'] ?? 0),
            'createdDate' => (string) ($feedback['createdDate'] ?? ''),
            'answer' => $feedback['answer'] ?? null,
            'state' => (string) ($feedback['state'] ?? ''),
            'orderStatus' => (string) ($feedback['orderStatus'] ?? ''),
            'matchingSize' => (string) ($feedback['matchingSize'] ?? ''),
            'bables' => array_values((array) ($feedback['bables'] ?? [])),
            'userName' => (string) ($feedback['userName'] ?? ''),
            'wasViewed' => (bool) ($feedback['wasViewed'] ?? false),
            'productDetails' => [
                'nmId' => (int) data_get($feedback, 'productDetails.nmId', data_get($feedback, 'productDetails.nmID', 0)),
                'productName' => (string) data_get($feedback, 'productDetails.productName', ''),
                'supplierArticle' => (string) data_get($feedback, 'productDetails.supplierArticle', ''),
                'brandName' => (string) data_get($feedback, 'productDetails.brandName', ''),
            ],
            'photo_links_count' => count($photoLinks),
            'has_video' => !empty($feedback['video']),
        ];
    }

    private function extractCampaignsForItems(array $items, array $campaignsById): array
    {
        $ids = [];
        foreach ($items as $item) {
            foreach ((array) ($item['advert_ids'] ?? []) as $campaignId) {
                $ids[(int) $campaignId] = true;
            }
        }

        $campaigns = [];
        foreach (array_keys($ids) as $id) {
            if (isset($campaignsById[$id])) {
                $campaigns[] = $campaignsById[$id];
            }
        }

        return $campaigns;
    }

    private function runBatchAnalysis(
        string $systemPrompt,
        string $model,
        array $batch,
        int $batchNumber,
        int $totalBatches,
        bool $isMarkdown = false,
        string $fieldInstructions = '',
    ): array {
        $datasetJson = json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($datasetJson === false) {
            throw new RuntimeException('Не удалось сериализовать batch dataset.');
        }

        $fieldRules = $fieldInstructions ?: "Всегда отвечай строго на русском языке.\n";

        if ($isMarkdown) {
            $prompt = $this->buildDatasetUserPrompt(
                fieldRules: $fieldRules,
                datasetJson: $datasetJson,
                batchNumber: $batchNumber,
                totalBatches: $totalBatches,
                includeJsonFormat: false,
            );

            $result = $this->requestTextAnalysisWithFallback(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                geminiModel: $model,
                temperature: 0.3,
                stageLabel: 'batch анализа (markdown)',
                expectJson: false,
                maxOutputTokens: $this->resolveMaxOutputTokens(true),
            );

            return [
                'markdown' => (string) ($result['text'] ?? ''),
                'text' => null,
                'json' => [],
                'input_tokens' => $result['input_tokens'] ?? 0,
                'output_tokens' => $result['output_tokens'] ?? 0,
                'total_tokens' => $result['total_tokens'] ?? 0,
                'provider' => $result['provider'] ?? 'gemini',
                'model' => $result['model'] ?? $model,
            ];
        }

        $prompt = $this->buildDatasetUserPrompt(
            fieldRules: $fieldRules,
            datasetJson: $datasetJson,
            batchNumber: $batchNumber,
            totalBatches: $totalBatches,
            includeJsonFormat: true,
        );

        return $this->requestTextAnalysisWithFallback(
            prompt: $prompt,
            systemPrompt: $systemPrompt,
            geminiModel: $model,
            temperature: 0.2,
            stageLabel: 'batch анализа',
            expectJson: true,
            maxOutputTokens: $this->resolveMaxOutputTokens(false),
        );
    }

    private function runFinalAggregation(
        string $systemPrompt,
        string $model,
        array $batchResults,
        array $datasetMeta,
        bool $isMarkdown = false,
        string $fieldInstructions = '',
    ): array {
        $fieldRules = $fieldInstructions ?: "Всегда отвечай строго на русском языке.\n";

        if ($isMarkdown) {
            $aggregationPayload = [
                'meta' => $datasetMeta,
                'partial_analyses' => array_values(array_filter(array_map(
                    static fn(array $batch): string => trim((string) ($batch['markdown'] ?? $batch['text'] ?? '')),
                    $batchResults,
                ))),
            ];

            $payloadJson = json_encode($aggregationPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payloadJson === false) {
                throw new RuntimeException('Не удалось сериализовать данные для финальной агрегации markdown.');
            }

            $prompt = $this->buildDatasetUserPrompt(
                fieldRules: $fieldRules,
                datasetJson: $payloadJson,
                batchNumber: 1,
                totalBatches: 1,
                includeJsonFormat: false,
                forAggregation: true,
                forMarkdownAggregation: true,
            );

            try {
                $result = $this->requestTextAnalysisWithFallback(
                    prompt: $prompt,
                    systemPrompt: $systemPrompt,
                    geminiModel: $model,
                    temperature: 0.1,
                    stageLabel: 'финальной агрегации (markdown)',
                    expectJson: false,
                    maxOutputTokens: $this->resolveMaxOutputTokens(true),
                );

                return [
                    'markdown' => trim((string) ($result['text'] ?? '')),
                    'text' => null,
                    'json' => [],
                    'input_tokens' => (int) ($result['input_tokens'] ?? 0),
                    'output_tokens' => (int) ($result['output_tokens'] ?? 0),
                    'total_tokens' => (int) ($result['total_tokens'] ?? 0),
                    'provider' => (string) ($result['provider'] ?? 'gemini'),
                    'model' => (string) ($result['model'] ?? $model),
                ];
            } catch (Throwable) {
                return $this->fallbackMergeMarkdownBatchResults($batchResults, $model);
            }
        }

        // Original JSON aggregation
        $aggregationPayload = [
            'meta' => $datasetMeta,
            'batch_analyses' => array_map(static function (array $batch, int $index): array {
                return [
                    'batch_number' => $index + 1,
                    'json' => (array) ($batch['json'] ?? []),
                    'text' => (string) ($batch['text'] ?? ''),
                ];
            }, $batchResults, array_keys($batchResults)),
        ];

        $payloadJson = json_encode($aggregationPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            throw new RuntimeException('Не удалось сериализовать данные для финальной агрегации.');
        }

        $prompt = $this->buildDatasetUserPrompt(
            fieldRules: $fieldRules,
            datasetJson: $payloadJson,
            batchNumber: 1,
            totalBatches: 1,
            includeJsonFormat: true,
            forAggregation: true,
        );

        try {
            return $this->requestTextAnalysisWithFallback(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                geminiModel: $model,
                temperature: 0.1,
                stageLabel: 'финальной агрегации',
                expectJson: true,
                maxOutputTokens: $this->resolveMaxOutputTokens(false),
            );
        } catch (Throwable) {
            $merged = $this->fallbackMergeBatchResults($batchResults);
            return [
                'text' => (string) ($merged['text'] ?? ''),
                'json' => (array) ($merged['json'] ?? []),
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'provider' => 'fallback_merge',
                'model' => 'fallback_merge',
            ];
        }
    }

    private function requestTextAnalysisWithFallback(
        string $prompt,
        string $systemPrompt,
        string $geminiModel,
        float $temperature,
        string $stageLabel,
        bool $expectJson = true,
        int $maxOutputTokens = self::MAX_OUTPUT_TOKENS_JSON,
    ): array {
        $geminiError = null;

        try {
            return $this->requestTextAnalysisGemini(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                geminiModel: $geminiModel,
                temperature: $temperature,
                stageLabel: $stageLabel,
                expectJson: $expectJson,
                maxOutputTokens: $maxOutputTokens,
            );
        } catch (Throwable $exception) {
            $geminiError = $exception;
        }

        try {
            return $this->requestTextAnalysisGpt(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                temperature: $temperature,
                stageLabel: $stageLabel,
                expectJson: $expectJson,
                maxOutputTokens: $maxOutputTokens,
            );
        } catch (Throwable $gptException) {
            $errors = [
                'Gemini: ' . ($geminiError?->getMessage() ?? 'unknown error'),
                'GPT: ' . $gptException->getMessage(),
            ];

            throw new RuntimeException('Не удалось получить корректный AI-ответ для ' . $stageLabel . '. ' . implode(' | ', $errors));
        }
    }

    private function requestTextAnalysisGemini(
        string $prompt,
        string $systemPrompt,
        string $geminiModel,
        float $temperature,
        string $stageLabel,
        bool $expectJson = true,
        int $maxOutputTokens = self::MAX_OUTPUT_TOKENS_JSON,
    ): array {
        $response = $this->geminiApiClient->generateProText($prompt, [
            'systemInstruction' => $systemPrompt,
            'model' => $geminiModel,
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => $maxOutputTokens,
            ],
        ]);

        if (!($response['success'] ?? false)) {
            $message = (string) (($response['messages'][0] ?? null) ?: 'Ошибка Gemini API');
            throw new RuntimeException($message);
        }

        $text = trim($this->geminiApiClient->extractText((array) ($response['data'] ?? [])));
        if ($text === '') {
            $this->logAiRequest(
                provider: 'gemini',
                model: (string) (data_get($response, 'data.modelVersion') ?: $geminiModel),
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                responseText: $text,
                inputTokens: 0,
                outputTokens: 0,
                totalTokens: 0,
                stageLabel: $stageLabel,
                statusCode: 200,
                errorMessage: 'Gemini вернул пустой ответ',
            );
            throw new RuntimeException('Gemini вернул пустой ответ для ' . $stageLabel . '.');
        }

        $usage = (array) data_get($response, 'data.usageMetadata', []);

        if (!$expectJson) {
            $inputTokens = (int) (data_get($usage, 'promptTokenCount') ?: 0);
            if ($inputTokens <= 0) {
                $inputTokens = $this->estimateTokensByLength($prompt);
            }

            $outputTokens = (int) (data_get($usage, 'candidatesTokenCount') ?: 0);
            if ($outputTokens <= 0) {
                $outputTokens = $this->estimateTokensByLength($text);
            }

            $totalTokens = (int) (data_get($usage, 'totalTokenCount') ?: 0);
            if ($totalTokens <= 0) {
                $totalTokens = $inputTokens + $outputTokens;
            }

            if ($outputTokens >= $maxOutputTokens) {
                Log::warning('AiCabinet Analyzer markdown possibly clipped by model output token limit', [
                    'stage' => $stageLabel,
                    'provider' => 'gemini',
                    'model' => (string) (data_get($response, 'data.modelVersion') ?: $geminiModel),
                    'output_tokens' => $outputTokens,
                    'max_output_tokens' => $maxOutputTokens,
                ]);
            }

            $this->logAiRequest(
                provider: 'gemini',
                model: (string) (data_get($response, 'data.modelVersion') ?: $geminiModel),
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                responseText: $text,
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                totalTokens: $totalTokens,
                stageLabel: $stageLabel,
                statusCode: 200,
                errorMessage: null,
            );

            return [
                'text' => $text,
                'json' => [],
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
                'provider' => 'gemini',
                'model' => (string) (data_get($response, 'data.modelVersion') ?: $geminiModel),
            ];
        }

        $parsed = $this->tryDecodeJsonFromText($text);
        if ($parsed === null) {
            $this->logAiRequest(
                provider: 'gemini',
                model: (string) (data_get($response, 'data.modelVersion') ?: $geminiModel),
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                responseText: $text,
                inputTokens: (int) (data_get($usage, 'promptTokenCount') ?: 0),
                outputTokens: (int) (data_get($usage, 'candidatesTokenCount') ?: 0),
                totalTokens: (int) (data_get($usage, 'totalTokenCount') ?: 0),
                stageLabel: $stageLabel,
                statusCode: 200,
                errorMessage: 'Gemini вернул невалидный JSON',
            );
            throw new RuntimeException('Gemini вернул невалидный JSON для ' . $stageLabel . '.');
        }

        $json = $this->normalizeAnalysisJson($text, $parsed);

        $inputTokens = (int) (data_get($usage, 'promptTokenCount') ?: 0);
        if ($inputTokens <= 0) {
            $inputTokens = $this->estimateTokensByLength($prompt);
        }

        $outputTokens = (int) (data_get($usage, 'candidatesTokenCount') ?: 0);
        if ($outputTokens <= 0) {
            $outputTokens = $this->estimateTokensByLength($text);
        }

        $totalTokens = (int) (data_get($usage, 'totalTokenCount') ?: 0);
        if ($totalTokens <= 0) {
            $totalTokens = $inputTokens + $outputTokens;
        }

        $this->logAiRequest(
            provider: 'gemini',
            model: (string) (data_get($response, 'data.modelVersion') ?: $geminiModel),
            prompt: $prompt,
            systemPrompt: $systemPrompt,
            responseText: $text,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            stageLabel: $stageLabel,
            statusCode: 200,
            errorMessage: null,
        );

        return [
            'text' => $text,
            'json' => $json,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'provider' => 'gemini',
            'model' => (string) (data_get($response, 'data.modelVersion') ?: $geminiModel),
        ];
    }

    private function requestTextAnalysisGpt(
        string $prompt,
        string $systemPrompt,
        float $temperature,
        string $stageLabel,
        bool $expectJson = true,
        int $maxOutputTokens = self::MAX_OUTPUT_TOKENS_JSON,
    ): array {
        $apiKey = (string) config('services.gpt.key');
        if (trim($apiKey) === '') {
            throw new RuntimeException('Не задан APP_GPT_KEY для fallback ИИ-анализа.');
        }

        $model = (string) config('services.gpt.model', self::GPT_FALLBACK_MODEL);
        $request = Http::timeout(120)
            ->acceptJson()
            ->withToken($apiKey)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ]);

        $proxy = (string) config('services.proxy', '');
        if (trim($proxy) !== '') {
            $request = $request->withOptions(['proxy' => $proxy]);
        }

        $response = $request->post('https://api.openai.com/v1/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $temperature,
            'max_tokens' => $maxOutputTokens,
        ]);

        if (!$response->successful()) {
            $body = mb_substr((string) $response->body(), 0, 800);
            $this->logAiRequest(
                provider: 'gpt',
                model: (string) config('services.gpt.model', self::GPT_FALLBACK_MODEL),
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                responseText: $body,
                inputTokens: 0,
                outputTokens: 0,
                totalTokens: 0,
                stageLabel: $stageLabel,
                statusCode: $response->status(),
                errorMessage: $body,
            );
            throw new RuntimeException(sprintf('GPT API вернул статус %d: %s', $response->status(), $body));
        }

        $raw = (array) $response->json();
        $text = trim((string) data_get($raw, 'choices.0.message.content', ''));
        if ($text === '') {
            $this->logAiRequest(
                provider: 'gpt',
                model: (string) ($raw['model'] ?? config('services.gpt.model', self::GPT_FALLBACK_MODEL)),
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                responseText: $text,
                inputTokens: (int) ($raw['usage']['prompt_tokens'] ?? 0),
                outputTokens: (int) ($raw['usage']['completion_tokens'] ?? 0),
                totalTokens: (int) ($raw['usage']['total_tokens'] ?? 0),
                stageLabel: $stageLabel,
                statusCode: 200,
                errorMessage: 'GPT вернул пустой ответ',
            );
            throw new RuntimeException('GPT вернул пустой ответ для ' . $stageLabel . '.');
        }

        $usage = (array) ($raw['usage'] ?? []);

        if (!$expectJson) {
            $inputTokens = (int) ($usage['prompt_tokens'] ?? 0);
            if ($inputTokens <= 0) {
                $inputTokens = $this->estimateTokensByLength($prompt);
            }

            $outputTokens = (int) ($usage['completion_tokens'] ?? 0);
            if ($outputTokens <= 0) {
                $outputTokens = $this->estimateTokensByLength($text);
            }

            $totalTokens = (int) ($usage['total_tokens'] ?? 0);
            if ($totalTokens <= 0) {
                $totalTokens = $inputTokens + $outputTokens;
            }

            if ($outputTokens >= $maxOutputTokens) {
                Log::warning('AiCabinet Analyzer markdown possibly clipped by model output token limit', [
                    'stage' => $stageLabel,
                    'provider' => 'gpt',
                    'model' => (string) ($raw['model'] ?? $model),
                    'output_tokens' => $outputTokens,
                    'max_output_tokens' => $maxOutputTokens,
                ]);
            }

            $this->logAiRequest(
                provider: 'gpt',
                model: (string) ($raw['model'] ?? $model),
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                responseText: $text,
                inputTokens: $inputTokens,
                outputTokens: $outputTokens,
                totalTokens: $totalTokens,
                stageLabel: $stageLabel,
                statusCode: 200,
                errorMessage: null,
            );

            return [
                'text' => $text,
                'json' => [],
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
                'provider' => 'gpt',
                'model' => (string) ($raw['model'] ?? $model),
            ];
        }

        $parsed = $this->tryDecodeJsonFromText($text);
        $json = $this->normalizeAnalysisJson($text, $parsed);
        $inputTokens = (int) ($usage['prompt_tokens'] ?? 0);
        if ($inputTokens <= 0) {
            $inputTokens = $this->estimateTokensByLength($prompt);
        }

        $outputTokens = (int) ($usage['completion_tokens'] ?? 0);
        if ($outputTokens <= 0) {
            $outputTokens = $this->estimateTokensByLength($text);
        }

        $totalTokens = (int) ($usage['total_tokens'] ?? 0);
        if ($totalTokens <= 0) {
            $totalTokens = $inputTokens + $outputTokens;
        }

        $this->logAiRequest(
            provider: 'gpt',
            model: (string) ($raw['model'] ?? $model),
            prompt: $prompt,
            systemPrompt: $systemPrompt,
            responseText: $text,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
            stageLabel: $stageLabel,
            statusCode: 200,
            errorMessage: null,
        );

        return [
            'text' => $text,
            'json' => $json,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'provider' => 'gpt',
            'model' => (string) ($raw['model'] ?? $model),
        ];
    }

    private function fallbackMergeBatchResults(array $batchResults): array
    {
        $texts = [];
        $insights = [];
        $risks = [];
        $actions = [];

        foreach ($batchResults as $batch) {
            $texts[] = (string) ($batch['text'] ?? '');
            $insights = array_merge($insights, (array) data_get($batch, 'json.insights', []));
            $risks = array_merge($risks, (array) data_get($batch, 'json.risks', []));
            $actions = array_merge($actions, (array) data_get($batch, 'json.actions', []));
        }

        return [
            'text' => trim(implode("\n\n", array_filter($texts))),
            'json' => [
                'summary' => 'Итог сформирован из нескольких частичных аналитик. Рекомендуется повторить запуск для более связного вывода.',
                'insights' => $insights,
                'risks' => $risks,
                'actions' => $actions,
                'metrics' => [],
            ],
        ];
    }

    private function fallbackMergeMarkdownBatchResults(array $batchResults, string $model): array
    {
        $parts = [];
        foreach ($batchResults as $batch) {
            $text = trim((string) ($batch['markdown'] ?? $batch['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return [
            'markdown' => trim(implode("\n\n", $parts)),
            'text' => null,
            'json' => [],
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'provider' => 'fallback_merge',
            'model' => $model,
        ];
    }

    private function extractJsonFromText(string $text): array
    {
        return $this->normalizeAnalysisJson($text, $this->tryDecodeJsonFromText($text));
    }

    private function tryDecodeJsonFromText(string $text): ?array
    {
        $clean = trim($text);

        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```[a-zA-Z]*\s*/', '', $clean) ?? $clean;
            $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
            $clean = trim($clean);
        }

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $clean, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function normalizeAnalysisJson(string $rawText, ?array $decoded): array
    {
        $json = is_array($decoded) ? $decoded : [];

        return [
            'summary' => (string) ($json['summary'] ?? $rawText),
            'insights' => array_values((array) ($json['insights'] ?? [])),
            'risks' => array_values((array) ($json['risks'] ?? [])),
            'actions' => array_values((array) ($json['actions'] ?? [])),
            'metrics' => array_values((array) ($json['metrics'] ?? [])),
            'meta' => (array) ($json['meta'] ?? []),
        ];
    }

    private function estimatePayloadSize(array $payload): int
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return 0;
        }

        return mb_strlen($json);
    }

    private function estimateTokensByLength(string $text): int
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0;
        }

        return max(1, (int) ceil(mb_strlen($trimmed) / 4));
    }

    private function resolveModel(string $requestedModel): string
    {
        $defaultModel = (string) config('services.gemini.pro_model', 'gemini-3.1-pro-preview');

        $allowedModels = array_values(array_unique(array_filter([
            'gemini',
            $defaultModel,
            'gemini-3.1-pro-preview',
        ])));

        $requestedModel = trim($requestedModel);
        if ($requestedModel === '' || $requestedModel === 'gemini') {
            return $defaultModel;
        }

        if (!in_array($requestedModel, $allowedModels, true)) {
            throw new RuntimeException('Указана неподдерживаемая модель Gemini.');
        }

        return $requestedModel;
    }

    private function resolveMaxOutputTokens(bool $isMarkdown): int
    {
        $configKey = $isMarkdown
            ? 'ai_cabinet_analyzer.markdown_max_output_tokens'
            : 'ai_cabinet_analyzer.json_max_output_tokens';

        $defaultValue = $isMarkdown
            ? self::MAX_OUTPUT_TOKENS_MARKDOWN
            : self::MAX_OUTPUT_TOKENS_JSON;

        $value = (int) config($configKey, $defaultValue);

        return max(512, min(32768, $value));
    }

    private function buildJsonOutputFormatInstructions(bool $forAggregation = false): string
    {
        $lines = [
            'Верни результат строго на русском языке.',
            'Верни JSON-объект без markdown-обертки.',
        ];

        if ($forAggregation) {
            $lines[] = 'Обязательные поля: summary (string), insights (array), risks (array), actions (array), metrics (array).';
            $lines[] = 'Для insights/risks/actions используй объекты: title, description, priority.';
        } else {
            $lines[] = 'Требуемые поля JSON: summary (string), insights (array), risks (array), actions (array), metrics (array).';
            $lines[] = 'Каждый элемент insights/risks/actions должен быть объектом с полями: title, description, priority.';
        }

        $lines[] = 'Поле metrics должно быть массивом объектов строго формата: {"key":"...","label":"...","value":...}.';
        $lines[] = 'key: технический ключ в snake_case, label: русское человекочитаемое название метрики, value: числовое или строковое значение.';
        $lines[] = 'Label всегда на русском языке.';

        return implode("\n", $lines);
    }

    private function buildMarkdownAggregationInstructions(): string
    {
        return implode("\n", [
            'Ниже переданы частичные результаты анализа одного и того же кабинета.',
            'Сформируй единый связный итоговый отчёт в формате Markdown на русском языке.',
            'Объедини выводы, убери дублирование, сохрани все важные инсайты, риски и рекомендации.',
            'НЕ упоминай batch, батчи, части, номера частей и технические детали разбиения данных.',
            'НЕ используй заголовки вида "Batch 1", "Часть 1" и подобные.',
            'Ответ должен выглядеть как единый профессиональный анализ кабинета.',
        ]);
    }

    private function buildDatasetUserPrompt(
        string $fieldRules,
        string $datasetJson,
        int $batchNumber,
        int $totalBatches,
        bool $includeJsonFormat,
        bool $forAggregation = false,
        bool $forMarkdownAggregation = false,
    ): string {
        $parts = [rtrim($fieldRules)];

        if ($includeJsonFormat) {
            $parts[] = $this->buildJsonOutputFormatInstructions($forAggregation);
        } elseif ($forMarkdownAggregation) {
            $parts[] = $this->buildMarkdownAggregationInstructions();
        }

        if ($totalBatches > 1 && !$forAggregation) {
            $parts[] = sprintf('Данные разбиты на части для обработки. Текущая часть: %d из %d. Не упоминай номер части в итоговом тексте.', $batchNumber, $totalBatches);
        }

        $parts[] = $forAggregation ? 'AGGREGATION_INPUT:' : 'DATASET:';
        $parts[] = $datasetJson;

        return implode("\n", $parts);
    }

    /**
     * Возвращает отформатированные инструкции по названиям полей для промпта ИИ.
     * Берет данные из конфига, чтобы ИИ использовал человекопонятные русские названия
     * вместо технических ключей из базы данных.
     */
    private function getFieldInstructions(): string
    {
        $funnelLabels = (array) config('ai_cabinet_analyzer.funnel_field_labels', []);
        $reviewsLabels = (array) config('ai_cabinet_analyzer.reviews_field_labels', []);
        $feedbacksLabels = (array) config('ai_cabinet_analyzer.feedbacks_field_labels', []);
        $labels = array_merge($funnelLabels, $reviewsLabels, $feedbacksLabels);

        if ($labels === []) {
            return "Всегда отвечай строго на русском языке.\n";
        }

        $lines = [];
        foreach ($labels as $key => $label) {
            $lines[] = "- {$key}: {$label}";
        }

        $fieldList = implode("\n", $lines);
        $feedbacksReference = trim((string) config('ai_cabinet_analyzer.feedbacks_api_field_reference', ''));
        $feedbacksInstructions = trim((string) config('ai_cabinet_analyzer.feedbacks_field_instructions', ''));

        $parts = [
            "ПРАВИЛА ПО НАЗВАНИЯМ ПОЛЕЙ (ОБЯЗАТЕЛЬНО СОБЛЮДАЙ):",
            "- Всегда отвечай строго на русском языке.",
            "- Никогда не используй в ответе технические названия колонок из данных (open_count, cart_count, buyout_count, order_count, spend, clicks, views, average_rating, bables, productValuation, orderStatus и т.п.).",
            "- Для всех метрик воронки продаж, агрегированных отзывов и сырых отзывов WB (feedbacks) используй ТОЛЬКО человекопонятные названия из этого списка:",
            '',
            $fieldList,
            '',
            (string) config('ai_cabinet_analyzer.field_instructions', ''),
        ];

        if ($feedbacksReference !== '') {
            $parts[] = '';
            $parts[] = $feedbacksReference;
        }

        if ($feedbacksInstructions !== '') {
            $parts[] = '';
            $parts[] = $feedbacksInstructions;
        }

        return implode("\n", $parts) . "\n\n";
    }

    /**
     * Логирует отдельный AI запрос (Gemini или GPT) в центральную таблицу ai_request_logs.
     * Вызывается из requestTextAnalysisGemini / Gpt для каждого реального вызова к провайдеру.
     */
    private function logAiRequest(
        string $provider,
        string $model,
        string $prompt,
        string $systemPrompt,
        ?string $responseText,
        int $inputTokens,
        int $outputTokens,
        int $totalTokens,
        string $stageLabel,
        int $statusCode,
        ?string $errorMessage
    ): void {
        if ($this->logUserId === null && $this->logSubscriberId === null) {
            return; // called without context (e.g. direct tests) — skip central logging
        }

        try {
            $requestPayload = [
                'prompt' => $prompt,
                'system_prompt' => $systemPrompt,
                'stage' => $stageLabel,
            ];

            $insert = [
                'user_id' => $this->logUserId,
                'subscriber_id' => $this->logSubscriberId,
                'task_type' => AiTaskType::WB_AI_CABINET_ANALYZER_AI->value,
                'marketplace' => 'wb',
                'provider' => $provider,
                'model' => $model,
                'request_payload' => $requestPayload,
                'response_text' => $responseText,
                'response_type' => 'text',
                'images_count' => 0,
                'videos_count' => 0,
                'input_tokens' => max(0, $inputTokens),
                'output_tokens' => max(0, $outputTokens),
                'total_tokens' => max(0, $totalTokens),
                'status_code' => $statusCode,
                'error_message' => $statusCode === 200 ? null : $errorMessage,
                'created_at' => now(),
            ];

            AiRequestLog::create($this->filterInsertByExistingColumns($insert));
        } catch (Throwable $exception) {
            Log::error('AiCabinet Analyzer AI request log write failed', [
                'exception' => $exception->getMessage(),
                'user_id' => $this->logUserId,
                'subscriber_id' => $this->logSubscriberId,
                'stage' => $stageLabel,
                'provider' => $provider,
            ]);
        }
    }

    /**
     * Безопасная вставка — оставляет только колонки, реально существующие в таблице.
     * Скопировано по аналогии с GeminiController.
     */
    private function filterInsertByExistingColumns(array $payload): array
    {
        static $availableColumns = null;

        if (! is_array($availableColumns)) {
            try {
                $availableColumns = Schema::getColumnListing('ai_request_logs');
            } catch (Throwable) {
                return $payload;
            }
        }

        if ($availableColumns === []) {
            return $payload;
        }

        $allowed = array_flip($availableColumns);

        return array_filter(
            $payload,
            static fn(mixed $value, string $key): bool => isset($allowed[$key]),
            ARRAY_FILTER_USE_BOTH
        );
    }
}
