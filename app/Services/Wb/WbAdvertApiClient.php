<?php

namespace App\Services\Wb;

use App\Http\Traits\GuzzleTrait;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Low-level client for Wildberries Promotion (advert-api).
 *
 * Contract: https://dev.wildberries.ru/docs/openapi/promotion
 * Base: https://advert-api.wildberries.ru
 */
class WbAdvertApiClient
{
    use GuzzleTrait;

    public const BASE_URL = 'https://advert-api.wildberries.ru';

    public const ADVERT_BATCH_SIZE = 50;

    /**
     * Budget deposit source (POST /adv/v1/budget/deposit).
     * 0 — account (счёт), 1 — balance (баланс), 3 — bonuses.
     */
    public const BUDGET_DEPOSIT_TYPE_ACCOUNT = 0;

    public const BUDGET_DEPOSIT_TYPE_BALANCE = 1;

    /** Statuses where WB API allows PATCH /adv/v0/auction/nms. */
    public const NMS_EDITABLE_STATUSES = [4, 9, 11];

    /**
     * Statuses where our A/B tool allows nm edits / reuse.
     * Active (9) is excluded even though API allows it — campaign must not be running.
     */
    public const SERVICE_NMS_EDITABLE_STATUSES = [4, 11];

    /**
     * GET /adv/v1/promotion/count
     *
     * @return array{success: bool, code: int, data: mixed, message?: string}
     */
    public function promotionCount(string $apiKey): array
    {
        return $this->request('GET', '/adv/v1/promotion/count', $apiKey);
    }

    /**
     * GET /api/advert/v2/adverts?ids=
     *
     * @param  list<int>  $ids
     * @return array{success: bool, code: int, data: mixed, message?: string}
     */
    public function getAdverts(string $apiKey, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ['success' => true, 'code' => 200, 'data' => ['adverts' => []]];
        }

        return $this->request('GET', '/api/advert/v2/adverts', $apiKey, [
            'ids' => implode(',', $ids),
        ]);
    }

    /**
     * Fetch all advert details in batches of 50.
     *
     * @param  list<int>  $ids
     * @return array{success: bool, code: int, adverts: list<array<string, mixed>>, messages: list<string>}
     */
    public function getAdvertsBatched(string $apiKey, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        $adverts = [];
        $messages = [];

        foreach (array_chunk($ids, self::ADVERT_BATCH_SIZE) as $batchIndex => $batch) {
            $result = $this->getAdverts($apiKey, $batch);
            if (! ($result['success'] ?? false)) {
                $messages[] = $result['message'] ?? 'Не удалось получить информацию о кампаниях';

                return [
                    'success' => false,
                    'code' => (int) ($result['code'] ?? 0),
                    'adverts' => $adverts,
                    'messages' => $messages,
                ];
            }

            $payload = $result['data'] ?? null;
            $rows = Arr::get($payload, 'adverts', is_array($payload) ? $payload : []);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $adverts[] = $row;
                    }
                }
            }

            if ($batchIndex > 0 && ! app()->runningUnitTests()) {
                usleep(200_000);
            }
        }

        return [
            'success' => true,
            'code' => 200,
            'adverts' => $adverts,
            'messages' => $messages,
        ];
    }

    /**
     * POST /adv/v2/seacat/save-ad
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, code: int, advert_id?: int, data: mixed, message?: string}
     */
    public function createSeacatCampaign(string $apiKey, array $payload): array
    {
        $result = $this->request('POST', '/adv/v2/seacat/save-ad', $apiKey, null, $payload);
        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $data = $result['data'];
        $advertId = is_numeric($data) ? (int) $data : (int) Arr::get(is_array($data) ? $data : [], 'advertId', 0);
        if ($advertId <= 0 && is_array($data)) {
            $advertId = (int) Arr::get($data, 'id', 0);
        }

        if ($advertId <= 0) {
            return [
                'success' => false,
                'code' => (int) ($result['code'] ?? 200),
                'data' => $data,
                'message' => 'WB API не вернул ID созданной кампании',
            ];
        }

        return [
            'success' => true,
            'code' => (int) ($result['code'] ?? 200),
            'advert_id' => $advertId,
            'data' => $data,
        ];
    }

    /**
     * PATCH /adv/v0/auction/nms — add/remove product cards.
     *
     * @param  list<int>  $add
     * @param  list<int>  $delete
     * @return array{success: bool, code: int, data: mixed, message?: string}
     */
    public function patchAuctionNms(string $apiKey, int $advertId, array $add = [], array $delete = []): array
    {
        $add = array_values(array_unique(array_filter(array_map('intval', $add), static fn (int $id): bool => $id > 0)));
        $delete = array_values(array_unique(array_filter(array_map('intval', $delete), static fn (int $id): bool => $id > 0)));

        $nms = [];
        if ($add !== []) {
            $nms['add'] = $add;
        }
        if ($delete !== []) {
            $nms['delete'] = $delete;
        }

        if ($nms === []) {
            return [
                'success' => false,
                'code' => 400,
                'data' => null,
                'message' => 'Не указаны товары для добавления или удаления',
            ];
        }

        $body = [
            'nms' => [
                [
                    'advert_id' => $advertId,
                    'nms' => $nms,
                ],
            ],
        ];

        return $this->request('PATCH', '/adv/v0/auction/nms', $apiKey, null, $body);
    }

    /**
     * GET /adv/v0/start?id= — run campaign (status 4 or 11).
     *
     * @return array{success: bool, code: int, data: mixed, message?: string}
     */
    public function startAdvert(string $apiKey, int $advertId): array
    {
        return $this->request('GET', '/adv/v0/start', $apiKey, ['id' => $advertId]);
    }

    /**
     * GET /adv/v0/pause?id= — pause campaign.
     *
     * @return array{success: bool, code: int, data: mixed, message?: string}
     */
    public function pauseAdvert(string $apiKey, int $advertId): array
    {
        return $this->request('GET', '/adv/v0/pause', $apiKey, ['id' => $advertId]);
    }

    /**
     * GET /adv/v0/delete?id= — delete campaign (status becomes -1, then removed).
     *
     * @return array{success: bool, code: int, data: mixed, message?: string}
     */
    public function deleteAdvert(string $apiKey, int $advertId): array
    {
        return $this->request('GET', '/adv/v0/delete', $apiKey, ['id' => $advertId]);
    }

    /**
     * GET /adv/v3/fullstats?ids=&beginDate=&endDate=
     *
     * @param  list<int>  $advertIds
     * @return array{success: bool, code: int, data: mixed, rows: list<array<string, mixed>>, message?: string}
     */
    public function fullstats(string $apiKey, array $advertIds, string $beginDate, string $endDate): array
    {
        $advertIds = array_values(array_unique(array_filter(
            array_map('intval', $advertIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($advertIds === []) {
            return [
                'success' => true,
                'code' => 200,
                'data' => [],
                'rows' => [],
            ];
        }

        $result = $this->request('GET', '/adv/v3/fullstats', $apiKey, [
            'ids' => implode(',', $advertIds),
            'beginDate' => $beginDate,
            'endDate' => $endDate,
        ]);

        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'code' => (int) ($result['code'] ?? 0),
                'data' => $result['data'] ?? null,
                'rows' => [],
                'message' => $result['message'] ?? 'Не удалось получить статистику кампании',
            ];
        }

        $payload = $result['data'] ?? null;
        $rows = [];
        if (is_array($payload)) {
            if (array_is_list($payload)) {
                $rows = $payload;
            } else {
                $nested = Arr::get($payload, 'adverts', Arr::get($payload, 'data', []));
                $rows = is_array($nested) ? $nested : [];
            }
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        return [
            'success' => true,
            'code' => (int) ($result['code'] ?? 200),
            'data' => $payload,
            'rows' => $normalized,
        ];
    }

    /**
     * Normalize a fullstats row (campaign-level totals).
     *
     * @param  array<string, mixed>  $row
     * @return array{views:int,clicks:int,spend:float,orders:int,ctr:float}
     */
    public function normalizeFullstatsRow(array $row): array
    {
        return [
            'views' => (int) Arr::get($row, 'views', Arr::get($row, 'viewsCount', 0)),
            'clicks' => (int) Arr::get($row, 'clicks', Arr::get($row, 'clicksCount', 0)),
            'spend' => (float) Arr::get($row, 'sum', Arr::get($row, 'spend', 0)),
            'orders' => (int) Arr::get($row, 'orders', Arr::get($row, 'orderCount', 0)),
            'ctr' => (float) Arr::get($row, 'ctr', 0),
        ];
    }

    /**
     * Extract stats for a campaign, optionally filtered by nm_id from nested days/apps/nms.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{views:int,clicks:int,spend:float,orders:int,ctr:float}
     */
    public function extractStatsForAdvert(array $rows, int $advertId, ?int $nmId = null): array
    {
        $empty = ['views' => 0, 'clicks' => 0, 'spend' => 0.0, 'orders' => 0, 'ctr' => 0.0];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rowAdvertId = (int) Arr::get($row, 'advertId', Arr::get($row, 'advert_id', 0));
            if ($rowAdvertId !== $advertId) {
                continue;
            }

            // Prefer nested nm breakdown when nmId is known.
            if ($nmId !== null && $nmId > 0) {
                $nmTotals = $this->sumNmStatsFromFullstatsRow($row, $nmId);
                if ($nmTotals !== null) {
                    return $nmTotals;
                }
            }

            return $this->normalizeFullstatsRow($row);
        }

        return $empty;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{views:int,clicks:int,spend:float,orders:int,ctr:float}|null
     */
    private function sumNmStatsFromFullstatsRow(array $row, int $nmId): ?array
    {
        $views = 0;
        $clicks = 0;
        $spend = 0.0;
        $orders = 0;
        $found = false;

        $days = Arr::get($row, 'days', []);
        if (! is_array($days) || $days === []) {
            // Some responses put nms at top level.
            $nms = Arr::get($row, 'nms', Arr::get($row, 'nmStats', []));
            if (is_array($nms)) {
                foreach ($nms as $nmRow) {
                    if (! is_array($nmRow)) {
                        continue;
                    }
                    $id = (int) Arr::get($nmRow, 'nmId', Arr::get($nmRow, 'nm_id', 0));
                    if ($id !== $nmId) {
                        continue;
                    }
                    $found = true;
                    $views += (int) Arr::get($nmRow, 'views', 0);
                    $clicks += (int) Arr::get($nmRow, 'clicks', 0);
                    $spend += (float) Arr::get($nmRow, 'sum', Arr::get($nmRow, 'spend', 0));
                    $orders += (int) Arr::get($nmRow, 'orders', 0);
                }
            }

            if (! $found) {
                return null;
            }

            return [
                'views' => $views,
                'clicks' => $clicks,
                'spend' => $spend,
                'orders' => $orders,
                'ctr' => $views > 0 ? round(($clicks / $views) * 100, 4) : 0.0,
            ];
        }

        foreach ($days as $day) {
            if (! is_array($day)) {
                continue;
            }
            $apps = Arr::get($day, 'apps', [$day]);
            if (! is_array($apps)) {
                continue;
            }
            foreach ($apps as $app) {
                if (! is_array($app)) {
                    continue;
                }
                $nms = Arr::get($app, 'nms', Arr::get($app, 'nm', []));
                if (! is_array($nms)) {
                    continue;
                }
                foreach ($nms as $nmRow) {
                    if (! is_array($nmRow)) {
                        continue;
                    }
                    $id = (int) Arr::get($nmRow, 'nmId', Arr::get($nmRow, 'nm_id', 0));
                    if ($id !== $nmId) {
                        continue;
                    }
                    $found = true;
                    $views += (int) Arr::get($nmRow, 'views', 0);
                    $clicks += (int) Arr::get($nmRow, 'clicks', 0);
                    $spend += (float) Arr::get($nmRow, 'sum', Arr::get($nmRow, 'spend', 0));
                    $orders += (int) Arr::get($nmRow, 'orders', 0);
                }
            }
        }

        if (! $found) {
            return null;
        }

        return [
            'views' => $views,
            'clicks' => $clicks,
            'spend' => $spend,
            'orders' => $orders,
            'ctr' => $views > 0 ? round(($clicks / $views) * 100, 4) : 0.0,
        ];
    }

    /**
     * GET /adv/v1/budget?id=
     *
     * @return array{success: bool, code: int, data: mixed, message?: string}
     */
    public function getBudget(string $apiKey, int $advertId): array
    {
        return $this->request('GET', '/adv/v1/budget', $apiKey, ['id' => $advertId]);
    }

    /**
     * POST /adv/v1/budget/deposit?id=
     *
     * Default source is balance (1) — typical promo wallet for sellers.
     *
     * @return array{success: bool, code: int, data: mixed, message?: string}
     */
    public function depositBudget(
        string $apiKey,
        int $advertId,
        int $sum,
        int $type = self::BUDGET_DEPOSIT_TYPE_BALANCE,
    ): array {
        return $this->request(
            'POST',
            '/adv/v1/budget/deposit',
            $apiKey,
            ['id' => $advertId],
            [
                'sum' => $sum,
                'type' => $type,
                'return' => true,
            ],
        );
    }

    /**
     * Extract total campaign budget (rubles) from GET /adv/v1/budget payload.
     */
    public function extractBudgetTotal(mixed $data): ?float
    {
        if (! is_array($data)) {
            return null;
        }

        foreach (['total', 'Total', 'cash', 'Cash'] as $key) {
            if (array_key_exists($key, $data) && is_numeric($data[$key])) {
                return (float) $data[$key];
            }
        }

        // Some responses nest under data/budget.
        $nested = Arr::get($data, 'budget.total') ?? Arr::get($data, 'data.total');
        if (is_numeric($nested)) {
            return (float) $nested;
        }

        return null;
    }

    /**
     * Collect advert IDs from promotion/count, optionally filtered by status.
     *
     * @param  list<int>|null  $statuses
     * @return array{success: bool, code: int, ids: list<int>, groups: list<array<string, mixed>>, message?: string}
     */
    public function listAdvertIds(string $apiKey, ?array $statuses = null): array
    {
        $result = $this->promotionCount($apiKey);
        if (! ($result['success'] ?? false)) {
            return [
                'success' => false,
                'code' => (int) ($result['code'] ?? 0),
                'ids' => [],
                'groups' => [],
                'message' => $result['message'] ?? 'Не удалось получить список кампаний',
            ];
        }

        $groups = Arr::get($result['data'], 'adverts', []);
        if (! is_array($groups)) {
            $groups = [];
        }

        $statusFilter = $statuses !== null
            ? array_map('intval', $statuses)
            : null;

        $ids = [];
        $normalizedGroups = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $status = (int) Arr::get($group, 'status', 0);
            if ($statusFilter !== null && ! in_array($status, $statusFilter, true)) {
                continue;
            }

            $type = (int) Arr::get($group, 'type', 0);
            $list = Arr::get($group, 'advert_list', []);
            $groupIds = [];

            foreach ((array) $list as $advert) {
                if (! is_array($advert)) {
                    continue;
                }
                $id = (int) Arr::get($advert, 'advertId', 0);
                if ($id > 0) {
                    $ids[] = $id;
                    $groupIds[] = $id;
                }
            }

            $normalizedGroups[] = [
                'type' => $type,
                'status' => $status,
                'count' => (int) Arr::get($group, 'count', count($groupIds)),
                'advert_ids' => $groupIds,
            ];
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return [
            'success' => true,
            'code' => 200,
            'ids' => $ids,
            'groups' => $normalizedGroups,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $query
     * @param  array<string, mixed>|null  $body
     * @return array{success: bool, code: int, data: mixed, message?: string}
     */
    private function request(string $method, string $path, string $apiKey, ?array $query = null, ?array $body = null): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return [
                'success' => false,
                'code' => 0,
                'data' => null,
                'message' => 'Пустой API-ключ кабинета Wildberries',
            ];
        }

        $url = self::BASE_URL.$path;
        $method = strtoupper($method);

        try {
            $raw = match ($method) {
                'GET' => $this->getRequest($url, $apiKey, $query ?? []),
                'POST' => $this->postWithQuery($url, $apiKey, $body ?? [], $query ?? []),
                'PATCH' => $this->patchRequest($url, $apiKey, $body ?? []),
                default => throw new \InvalidArgumentException("Unsupported method {$method}"),
            };
        } catch (\Throwable $e) {
            Log::warning('[WbAdvertApiClient] request failed', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'code' => 0,
                'data' => null,
                'message' => 'Ошибка сети при обращении к WB API продвижения',
            ];
        }

        return $this->normalizeResponse($raw, $method, $path);
    }

    /**
     * POST with optional query string (budget/deposit uses ?id=).
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function postWithQuery(string $url, string $apiKey, array $body, array $query): array
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $this->postRequest($url, $apiKey, $body);
    }

    /**
     * @param  array<string, mixed>  $raw  GuzzleTrait result
     * @return array{success: bool, code: int, data: mixed, message?: string}
     */
    private function normalizeResponse(array $raw, string $method, string $path): array
    {
        $code = (int) ($raw['code'] ?? 0);
        $body = $raw['response'] ?? null;

        if ($code === 204 || ($code >= 200 && $code < 300 && ($body === '' || $body === null))) {
            return ['success' => true, 'code' => $code, 'data' => null];
        }

        $decoded = null;
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // Plain text error body from some WB endpoints.
                if ($code >= 200 && $code < 300 && is_numeric(trim($body))) {
                    return ['success' => true, 'code' => $code, 'data' => (int) trim($body)];
                }

                if ($code >= 200 && $code < 300) {
                    return ['success' => true, 'code' => $code, 'data' => $body];
                }

                return [
                    'success' => false,
                    'code' => $code,
                    'data' => $body,
                    'message' => $this->humanErrorMessage($code, $body),
                ];
            }
        }

        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'code' => $code, 'data' => $decoded];
        }

        $message = $this->extractErrorMessage($decoded, $body, $code);

        Log::warning('[WbAdvertApiClient] API error', [
            'method' => $method,
            'path' => $path,
            'code' => $code,
            'message' => $message,
        ]);

        return [
            'success' => false,
            'code' => $code,
            'data' => $decoded ?? $body,
            'message' => $message,
        ];
    }

    private function extractErrorMessage(mixed $decoded, mixed $rawBody, int $code): string
    {
        if (is_array($decoded)) {
            foreach (['detail', 'error', 'title', 'message', 'errorText'] as $key) {
                $value = $decoded[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return $this->humanErrorMessage($code, trim($value));
                }
            }
        }

        if (is_string($decoded) && trim($decoded) !== '') {
            return $this->humanErrorMessage($code, trim($decoded));
        }

        if (is_string($rawBody) && trim($rawBody) !== '') {
            return $this->humanErrorMessage($code, trim($rawBody));
        }

        return $this->humanErrorMessage($code, null);
    }

    private function humanErrorMessage(int $code, ?string $detail): string
    {
        $base = match ($code) {
            401, 403 => 'Нет доступа к API продвижения. Проверьте API-ключ и категорию «Продвижение».',
            429 => 'Превышен лимит запросов к API продвижения Wildberries. Повторите через минуту.',
            400 => 'Некорректный запрос к API продвижения Wildberries.',
            default => 'Ошибка API продвижения Wildberries.',
        };

        if ($detail !== null && $detail !== '') {
            $translated = $this->translateWbErrorDetail($detail);
            if ($translated !== null) {
                return $translated;
            }

            if (! str_contains($base, $detail)) {
                // Prefer concrete WB text when present (often already in Russian).
                if ($code === 400 || mb_strlen($detail) < 300) {
                    return $detail;
                }
            }
        }

        return $base;
    }

    /**
     * Map known English WB Advert API error phrases to Russian UX copy.
     */
    private function translateWbErrorDetail(string $detail): ?string
    {
        $normalized = mb_strtolower(trim($detail));

        // Strip common prefixes: "Invalid Params: …", "error: …"
        $normalized = (string) preg_replace('/^(invalid\s+params?\s*:\s*|error\s*:\s*)/iu', '', $normalized);

        if (str_contains($normalized, "bid_type must be 'manual' for cpc")
            || str_contains($normalized, 'bid_type must be "manual" for cpc')
            || (str_contains($normalized, 'bid_type') && str_contains($normalized, 'manual') && str_contains($normalized, 'cpc'))
        ) {
            return 'Для кампаний с оплатой CPC (за клики) доступна только ручная ставка. Выберите «Ручная ставка» или смените тип оплаты на CPM.';
        }

        return null;
    }
}
