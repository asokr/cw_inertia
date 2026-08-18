<?php

namespace App\Services\Oz\AiCabinetAnalyzer;

use App\Services\Oz\AiCabinetAnalyzer\Support\OzAiCabinetAnalyzerRequestGuard;
use App\Services\Ozon\OzonApiService;
use Throwable;

/**
 * Рейтинги продавца: POST /v1/rating/summary
 */
class OzAiCabinetAnalyzerSellerRatingCollector
{
    private OzAiCabinetAnalyzerRequestGuard $guard;

    public function __construct(
        private readonly OzonApiService $ozonApiService,
    ) {
        $this->guard = new OzAiCabinetAnalyzerRequestGuard(
            maxAttempts: 3,
            minIntervalMs: 400,
            rateLimitBackoffMs: 15_000,
        );
    }

    public function requestCount(): int
    {
        return $this->guard->requestCount();
    }

    public function retryCount(): int
    {
        return $this->guard->retryCount();
    }

    /**
     * @return array{summary: array<string, mixed>|null, warnings: list<array<string, mixed>>}
     */
    public function collect(string $apiKey, string $clientId, ?callable $onStage = null): array
    {
        $onStage && $onStage('seller_rating');

        try {
            $response = $this->guard->requestWithRetry(
                fn () => $this->ozonApiService->getSellerRatingSummary($apiKey, $clientId),
                'rating/summary',
            );
        } catch (Throwable $e) {
            return [
                'summary' => null,
                'warnings' => [[
                    'type' => 'seller_rating_fetch_failed',
                    'message' => $e->getMessage(),
                ]],
            ];
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        $groups = [];
        foreach ((array) ($data['groups'] ?? []) as $group) {
            if (! is_array($group)) {
                continue;
            }
            $items = [];
            foreach ((array) ($group['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $items[] = [
                    'name' => isset($item['name']) ? (string) $item['name'] : null,
                    'rating' => isset($item['rating']) ? (string) $item['rating'] : null,
                    'current_value' => is_numeric($item['current_value'] ?? null) ? (float) $item['current_value'] : null,
                    'past_value' => is_numeric($item['past_value'] ?? null) ? (float) $item['past_value'] : null,
                    'status' => isset($item['status']) ? (string) $item['status'] : null,
                    'value_type' => isset($item['value_type']) ? (string) $item['value_type'] : null,
                    'change_direction' => data_get($item, 'change.direction'),
                    'change_meaning' => data_get($item, 'change.meaning'),
                ];
            }
            $groups[] = [
                'group_name' => isset($group['group_name']) ? (string) $group['group_name'] : null,
                'items' => $items,
            ];
        }

        return [
            'summary' => [
                'groups' => $groups,
                'premium' => (bool) ($data['premium'] ?? false),
                'premium_plus' => (bool) ($data['premium_plus'] ?? false),
                'penalty_score_exceeded' => (bool) ($data['penalty_score_exceeded'] ?? false),
                'localization_index' => is_array($data['localization_index'] ?? null)
                    ? $data['localization_index']
                    : [],
            ],
            'warnings' => [],
        ];
    }
}
