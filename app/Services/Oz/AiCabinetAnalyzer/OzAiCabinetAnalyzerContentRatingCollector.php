<?php

namespace App\Services\Oz\AiCabinetAnalyzer;

use App\Services\Oz\AiCabinetAnalyzer\Support\OzAiCabinetAnalyzerRequestGuard;
use App\Services\Ozon\OzonApiService;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Контент-рейтинг карточек: POST /v1/product/rating-by-sku
 */
class OzAiCabinetAnalyzerContentRatingCollector
{
    private const SKU_BATCH = 100;

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
     * @param  list<int>  $skus
     * @return array{by_sku: array<int, array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function collect(string $apiKey, string $clientId, array $skus, ?callable $onStage = null): array
    {
        $skus = array_values(array_unique(array_filter(array_map('intval', $skus))));
        $bySku = [];
        $warnings = [];

        if ($skus === []) {
            return ['by_sku' => [], 'warnings' => []];
        }

        foreach (array_chunk($skus, self::SKU_BATCH) as $batchIndex => $batch) {
            $onStage && $onStage(sprintf('content_rating_batch_%d', $batchIndex + 1));

            try {
                $response = $this->guard->requestWithRetry(
                    fn () => $this->ozonApiService->getProductRatingBySku($apiKey, $clientId, [
                        'skus' => array_map('strval', $batch),
                    ]),
                    'product/rating-by-sku',
                );
            } catch (Throwable $e) {
                $warnings[] = [
                    'type' => 'content_rating_batch_failed',
                    'message' => $e->getMessage(),
                    'batch' => $batchIndex + 1,
                ];
                continue;
            }

            $products = (array) Arr::get($response, 'data.products', []);
            foreach ($products as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $sku = (int) ($item['sku'] ?? 0);
                if ($sku <= 0) {
                    continue;
                }
                $bySku[$sku] = $this->normalizeRating($item);
            }
        }

        return ['by_sku' => $bySku, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeRating(array $item): array
    {
        $groups = [];
        foreach ((array) ($item['groups'] ?? []) as $group) {
            if (! is_array($group)) {
                continue;
            }

            $unfulfilled = [];
            foreach ((array) ($group['conditions'] ?? []) as $condition) {
                if (! is_array($condition) || ($condition['fulfilled'] ?? false) === true) {
                    continue;
                }
                $description = trim((string) ($condition['description'] ?? ''));
                if ($description === '') {
                    continue;
                }
                $unfulfilled[] = $description;
            }

            $groups[] = [
                'key' => isset($group['key']) ? (string) $group['key'] : null,
                'name' => isset($group['name']) ? (string) $group['name'] : null,
                'rating' => is_numeric($group['rating'] ?? null) ? (float) $group['rating'] : null,
                'weight' => is_numeric($group['weight'] ?? null) ? (float) $group['weight'] : null,
                'unfulfilled' => $unfulfilled,
            ];
        }

        return [
            'rating' => is_numeric($item['rating'] ?? null) ? (float) $item['rating'] : null,
            'groups' => $groups,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyBlock(): array
    {
        return [
            'rating' => null,
            'groups' => [],
        ];
    }
}
