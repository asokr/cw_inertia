<?php

namespace App\Services\Oz\AiCabinetAnalyzer;

use App\Services\Oz\AiCabinetAnalyzer\Support\OzAiCabinetAnalyzerRequestGuard;
use App\Services\Ozon\OzonApiService;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Акции Ozon: GET /v1/actions + POST /v1/actions/products
 */
class OzAiCabinetAnalyzerPromosCollector
{
    private const PAGE_LIMIT = 100;

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
     * @return array{
     *   actions: list<array<string, mixed>>,
     *   by_product_id: array<int, list<array<string, mixed>>>,
     *   warnings: list<array<string, mixed>>
     * }
     */
    public function collect(string $apiKey, string $clientId, ?callable $onStage = null): array
    {
        $warnings = [];
        $actions = [];
        $byProductId = [];

        $onStage && $onStage('promos_list');
        try {
            $response = $this->guard->requestWithRetry(
                fn () => $this->ozonApiService->getActions($apiKey, $clientId),
                'actions',
            );
        } catch (Throwable $e) {
            return [
                'actions' => [],
                'by_product_id' => [],
                'warnings' => [[
                    'type' => 'promos_list_failed',
                    'message' => $e->getMessage(),
                ]],
            ];
        }

        $rawActions = (array) Arr::get($response, 'data.result', []);
        foreach ($rawActions as $action) {
            if (! is_array($action)) {
                continue;
            }
            $id = (int) ($action['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $normalized = [
                'id' => $id,
                'title' => isset($action['title']) ? (string) $action['title'] : null,
                'action_type' => isset($action['action_type']) ? (string) $action['action_type'] : null,
                'date_start' => isset($action['date_start']) ? (string) $action['date_start'] : null,
                'date_end' => isset($action['date_end']) ? (string) $action['date_end'] : null,
                'is_participating' => (bool) ($action['is_participating'] ?? false),
                'participating_products_count' => (int) ($action['participating_products_count'] ?? 0),
                'potential_products_count' => (int) ($action['potential_products_count'] ?? 0),
                'discount_type' => isset($action['discount_type']) ? (string) $action['discount_type'] : null,
                'discount_value' => is_numeric($action['discount_value'] ?? null)
                    ? (float) $action['discount_value']
                    : null,
            ];
            $actions[] = $normalized;

            if (! $normalized['is_participating'] && $normalized['participating_products_count'] <= 0) {
                continue;
            }

            try {
                $products = $this->fetchActionProducts($apiKey, $clientId, $id, $onStage);
            } catch (Throwable $e) {
                $warnings[] = [
                    'type' => 'promos_products_failed',
                    'message' => $e->getMessage(),
                    'action_id' => $id,
                ];
                continue;
            }

            foreach ($products as $row) {
                $productId = (int) ($row['id'] ?? $row['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }
                if (! isset($byProductId[$productId])) {
                    $byProductId[$productId] = [];
                }
                $byProductId[$productId][] = [
                    'action_id' => $id,
                    'title' => $normalized['title'],
                    'action_price' => is_numeric($row['action_price'] ?? null) ? (float) $row['action_price'] : null,
                    'max_action_price' => is_numeric($row['max_action_price'] ?? null)
                        ? (float) $row['max_action_price']
                        : null,
                    'price' => is_numeric($row['price'] ?? null) ? (float) $row['price'] : null,
                    'stock' => isset($row['stock']) ? (int) $row['stock'] : null,
                    'add_mode' => isset($row['add_mode']) ? (string) $row['add_mode'] : null,
                ];
            }
        }

        return [
            'actions' => $actions,
            'by_product_id' => $byProductId,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchActionProducts(string $apiKey, string $clientId, int $actionId, ?callable $onStage): array
    {
        $products = [];
        $lastId = '';
        $page = 0;

        do {
            $page++;
            $onStage && $onStage(sprintf('promos_action_%d_page_%d', $actionId, $page));

            $payload = [
                'action_id' => $actionId,
                'limit' => self::PAGE_LIMIT,
            ];
            if ($lastId !== '') {
                $payload['last_id'] = $lastId;
            }

            $response = $this->guard->requestWithRetry(
                fn () => $this->ozonApiService->getActionProducts($apiKey, $clientId, $payload),
                'actions/products',
            );

            $batch = (array) Arr::get($response, 'data.result.products', []);
            foreach ($batch as $item) {
                if (is_array($item)) {
                    $products[] = $item;
                }
            }

            $nextLastId = Arr::get($response, 'data.result.last_id', '');
            $lastId = is_scalar($nextLastId) ? (string) $nextLastId : '';
        } while ($batch !== [] && $lastId !== '' && $page < 100);

        return $products;
    }
}
