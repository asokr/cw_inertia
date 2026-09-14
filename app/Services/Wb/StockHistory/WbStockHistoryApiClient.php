<?php

namespace App\Services\Wb\StockHistory;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Запросы к API Wildberries для истории остатков и заказов.
 */
class WbStockHistoryApiClient
{
    private const ANALYTICS_BASE = 'https://seller-analytics-api.wildberries.ru';

    private const STATISTICS_BASE = 'https://statistics-api.wildberries.ru';

    private const CONTENT_BASE = 'https://content-api.wildberries.ru';

    private const MARKETPLACE_BASE = 'https://marketplace-api.wildberries.ru';

    private const STOCKS_PAGE_LIMIT = 250000;

    private const CSV_POLL_ATTEMPTS = 40;

    private const CSV_POLL_SLEEP_MS = 3000;

    private const ORDERS_PAGE_SLEEP_MS = 60_000;

    /**
     * Текущие остатки на складах WB.
     *
     * @return list<array{
     *     nmId: int,
     *     chrtId: int,
     *     warehouseId: int,
     *     warehouseName: string,
     *     quantity: int,
     *     inWayToClient: int,
     *     inWayFromClient: int
     * }>
     */
    public function fetchCurrentStocks(string $apiKey): array
    {
        $items = [];
        $offset = 0;

        do {
            $response = $this->http($apiKey)
                ->post(self::ANALYTICS_BASE.'/api/analytics/v1/stocks-report/wb-warehouses', [
                    'limit' => self::STOCKS_PAGE_LIMIT,
                    'offset' => $offset,
                ]);

            $this->assertOk($response->status(), 'текущие остатки', $response->json());

            if ($response->status() === 204) {
                break;
            }

            $page = Arr::get($response->json(), 'data.items', []);
            if (! is_array($page) || $page === []) {
                break;
            }

            foreach ($page as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $items[] = [
                    'nmId' => (int) ($row['nmId'] ?? 0),
                    'chrtId' => (int) ($row['chrtId'] ?? 0),
                    'warehouseId' => (int) ($row['warehouseId'] ?? 0),
                    'warehouseName' => (string) ($row['warehouseName'] ?? 'Склад WB'),
                    'quantity' => (int) ($row['quantity'] ?? 0),
                    'inWayToClient' => (int) ($row['inWayToClient'] ?? 0),
                    'inWayFromClient' => (int) ($row['inWayFromClient'] ?? 0),
                ];
            }

            $count = count($page);
            $offset += $count;
        } while ($count >= self::STOCKS_PAGE_LIMIT);

        return $items;
    }

    /**
     * Справочник складов WB: id → название.
     *
     * @return array<int, string>
     */
    public function fetchOffices(string $apiKey): array
    {
        $response = $this->http($apiKey)
            ->get(self::MARKETPLACE_BASE.'/api/v3/offices');
        $this->assertOk($response->status(), 'список складов WB', $response->json());

        $payload = $response->json();
        if (! is_array($payload)) {
            return [];
        }

        $map = [];
        foreach ($payload as $office) {
            if (! is_array($office)) {
                continue;
            }
            $id = (int) ($office['id'] ?? 0);
            $name = trim((string) ($office['name'] ?? ''));
            if ($id > 0 && $name !== '') {
                $map[$id] = $name;
            }
        }

        return $map;
    }

    public function isPlaceholderWarehouseId(int $warehouseId): bool
    {
        return $warehouseId <= 0 || $warehouseId === -999999;
    }

    public function isPlaceholderWarehouseName(string $name): bool
    {
        $normalized = mb_strtolower(trim($name));
        if ($normalized === '' || $normalized === 'склад wb' || $normalized === 'маркетплейс') {
            return true;
        }

        return str_starts_with($normalized, 'всего ')
            || str_starts_with($normalized, 'в пути');
    }

    /**
     * Карточки кабинета: предмет, артикул, размеры, баркоды.
     *
     * @return list<array{
     *     nmId: int,
     *     vendorCode: string,
     *     subject: string,
     *     name: string,
     *     sizes: list<array{chrtId: int, techSize: string, barcode: string}>
     * }>
     */
    public function fetchCards(string $apiKey): array
    {
        $cards = [];
        $cursor = [
            'limit' => 100,
        ];
        $updatedAt = null;
        $nmId = null;

        do {
            $payload = [
                'settings' => [
                    'cursor' => array_filter([
                        'limit' => 100,
                        'updatedAt' => $updatedAt,
                        'nmID' => $nmId,
                    ], static fn ($value) => $value !== null && $value !== ''),
                    'filter' => [
                        'withPhoto' => -1,
                    ],
                ],
            ];

            $response = $this->http($apiKey)
                ->post(self::CONTENT_BASE.'/content/v2/get/cards/list?locale=ru', $payload);

            $this->assertOk($response->status(), 'карточки товаров', $response->json());

            $json = $response->json();
            $page = Arr::get($json, 'cards', Arr::get($json, 'data.cards', []));
            if (! is_array($page) || $page === []) {
                break;
            }

            foreach ($page as $card) {
                if (! is_array($card)) {
                    continue;
                }
                $sizes = [];
                foreach ((array) ($card['sizes'] ?? []) as $size) {
                    if (! is_array($size)) {
                        continue;
                    }
                    $barcodes = $size['skus'] ?? $size['barcode'] ?? [];
                    if (is_string($barcodes)) {
                        $barcodes = [$barcodes];
                    }
                    $barcode = '';
                    if (is_array($barcodes) && $barcodes !== []) {
                        $barcode = (string) reset($barcodes);
                    }
                    $sizes[] = [
                        'chrtId' => (int) ($size['chrtID'] ?? $size['chrtId'] ?? 0),
                        'techSize' => (string) ($size['techSize'] ?? $size['wbSize'] ?? ''),
                        'barcode' => $barcode,
                    ];
                }

                $cards[] = [
                    'nmId' => (int) ($card['nmID'] ?? $card['nmId'] ?? 0),
                    'vendorCode' => (string) ($card['vendorCode'] ?? ''),
                    'subject' => (string) ($card['subjectName'] ?? $card['object'] ?? ''),
                    'name' => (string) ($card['title'] ?? $card['name'] ?? ''),
                    'sizes' => $sizes,
                ];
            }

            $cursorData = Arr::get($json, 'cursor', Arr::get($json, 'data.cursor', []));
            $updatedAt = is_array($cursorData) ? ($cursorData['updatedAt'] ?? null) : null;
            $nmId = is_array($cursorData) ? ($cursorData['nmID'] ?? $cursorData['nmId'] ?? null) : null;
            $total = (int) (is_array($cursorData) ? ($cursorData['total'] ?? count($page)) : count($page));
        } while ($total >= 100 && $updatedAt);

        return $cards;
    }

    /**
     * История остатков по дням из CSV STOCK_HISTORY_DAILY_CSV.
     *
     * @return list<array{
     *     vendorCode: string,
     *     name: string,
     *     nmId: int,
     *     subjectName: string,
     *     sizeName: string,
     *     chrtId: int,
     *     officeName: string,
     *     stocks: array<string, int>
     * }>
     */
    public function fetchDailyStockHistory(string $apiKey, string $from, string $to): array
    {
        $downloadId = (string) Str::uuid();

        $create = $this->http($apiKey)
            ->post(self::ANALYTICS_BASE.'/api/v2/nm-report/downloads', [
                'id' => $downloadId,
                'reportType' => 'STOCK_HISTORY_DAILY_CSV',
                'userReportName' => 'stock-history-'.$from.'-'.$to,
                'params' => [
                    'currentPeriod' => [
                        'start' => $from,
                        'end' => $to,
                    ],
                    'stockType' => 'wb',
                    'skipDeletedNm' => false,
                ],
            ]);

        $this->assertOk($create->status(), 'создание отчёта по остаткам', $create->json());

        $status = $this->waitCsvReady($apiKey, $downloadId);
        if ($status !== 'SUCCESS') {
            throw new RuntimeException('Не удалось получить историю остатков. Попробуйте позже.');
        }

        $file = $this->http($apiKey)
            ->withHeaders(['Accept' => 'application/zip'])
            ->get(self::ANALYTICS_BASE.'/api/v2/nm-report/downloads/file/'.$downloadId);

        if ($file->status() < 200 || $file->status() >= 300) {
            throw new RuntimeException('Не удалось скачать историю остатков. Попробуйте позже.');
        }

        return $this->parseDailyStockCsvZip((string) $file->body());
    }

    /**
     * Заказы с пагинацией по lastChangeDate.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchOrders(string $apiKey, string $dateFrom, int $flag = 0): array
    {
        $rows = [];
        $cursor = $dateFrom;
        $guard = 0;

        while ($guard < 500) {
            $guard++;
            $response = $this->http($apiKey)
                ->get(self::STATISTICS_BASE.'/api/v1/supplier/orders', [
                    'dateFrom' => $cursor,
                    'flag' => $flag,
                ]);

            $this->assertOk($response->status(), 'заказы', $response->json());

            $page = $response->json();
            if (! is_array($page) || $page === []) {
                break;
            }

            foreach ($page as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            if ($flag === 1) {
                break;
            }

            $last = end($page);
            $next = is_array($last) ? (string) ($last['lastChangeDate'] ?? '') : '';
            if ($next === '' || $next === $cursor || count($page) < 1000) {
                if (count($page) < 80000) {
                    break;
                }
            }
            if ($next === '' || $next === $cursor) {
                break;
            }
            $cursor = $next;
            $this->sleepMs(self::ORDERS_PAGE_SLEEP_MS);
        }

        return $rows;
    }

    private function waitCsvReady(string $apiKey, string $downloadId): string
    {
        $status = 'WAITING';

        for ($attempt = 0; $attempt < self::CSV_POLL_ATTEMPTS; $attempt++) {
            if ($attempt > 0) {
                $this->sleepMs(self::CSV_POLL_SLEEP_MS);
            }

            $response = $this->http($apiKey)
                ->get(self::ANALYTICS_BASE.'/api/v2/nm-report/downloads', [
                    'filter[downloadIds]' => [$downloadId],
                ]);

            $this->assertOk($response->status(), 'статус отчёта по остаткам', $response->json());

            $items = Arr::get($response->json(), 'data', $response->json());
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $id = (string) ($item['id'] ?? $item['downloadId'] ?? '');
                if ($id !== '' && $id !== $downloadId) {
                    continue;
                }
                $status = strtoupper((string) ($item['status'] ?? $item['state'] ?? 'WAITING'));
                if (in_array($status, ['SUCCESS', 'DONE', 'READY'], true)) {
                    return 'SUCCESS';
                }
                if (in_array($status, ['FAILED', 'ERROR'], true)) {
                    return 'FAILED';
                }
            }
        }

        return $status;
    }

    /**
     * @return list<array{
     *     vendorCode: string,
     *     name: string,
     *     nmId: int,
     *     subjectName: string,
     *     sizeName: string,
     *     chrtId: int,
     *     officeName: string,
     *     warehouseId: int,
     *     stocks: array<string, int>
     * }>
     */
    public function parseDailyStockCsvZip(string $binary): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wbsh');
        if ($tmp === false) {
            throw new RuntimeException('Не удалось сохранить отчёт по остаткам.');
        }

        file_put_contents($tmp, $binary);

        $zip = new ZipArchive;
        $opened = $zip->open($tmp);
        if ($opened !== true) {
            @unlink($tmp);

            // Если пришёл уже CSV, а не ZIP.
            return $this->parseDailyStockCsv($binary);
        }

        $csv = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (! str_ends_with(strtolower($name), '.csv')) {
                continue;
            }
            $csv = (string) $zip->getFromIndex($i);
            break;
        }
        $zip->close();
        @unlink($tmp);

        if ($csv === '') {
            throw new RuntimeException('В отчёте по остаткам нет данных.');
        }

        return $this->parseDailyStockCsv($csv);
    }

    /**
     * @return list<array{
     *     vendorCode: string,
     *     name: string,
     *     nmId: int,
     *     subjectName: string,
     *     sizeName: string,
     *     chrtId: int,
     *     officeName: string,
     *     warehouseId: int,
     *     stocks: array<string, int>
     * }>
     */
    public function parseDailyStockCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
        if ($lines === []) {
            return [];
        }

        $headerLine = (string) array_shift($lines);
        $delimiter = substr_count($headerLine, ';') >= substr_count($headerLine, ',') ? ';' : ',';
        $header = str_getcsv($headerLine, $delimiter);

        $map = [];
        $dateColumns = [];
        foreach ($header as $index => $title) {
            $title = trim((string) $title);
            $map[mb_strtolower($title)] = $index;
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $title, $m)) {
                $dateColumns[$index] = $m[3].'-'.$m[2].'-'.$m[1];
            }
        }

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line, $delimiter);
            $nmId = (int) $this->col($cols, $map, ['nmid', 'nm_id']);
            if ($nmId <= 0) {
                continue;
            }

            $stocks = [];
            foreach ($dateColumns as $index => $isoDate) {
                $raw = trim((string) ($cols[$index] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $stocks[$isoDate] = (int) $raw;
            }

            $rows[] = [
                'vendorCode' => (string) $this->col($cols, $map, ['vendorcode', 'vendor_code']),
                'name' => (string) $this->col($cols, $map, ['name']),
                'nmId' => $nmId,
                'subjectName' => (string) $this->col($cols, $map, ['subjectname', 'subject_name']),
                'sizeName' => (string) $this->col($cols, $map, ['sizename', 'size_name']),
                'chrtId' => (int) $this->col($cols, $map, ['chrtid', 'chrt_id']),
                'officeName' => (string) $this->col($cols, $map, ['officename', 'office_name']),
                'warehouseId' => (int) $this->col($cols, $map, ['warehouseid', 'warehouse_id', 'officeid', 'office_id']),
                'stocks' => $stocks,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $cols
     * @param  array<string, int>  $map
     * @param  list<string>  $keys
     */
    private function col(array $cols, array $map, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($map[$key]) && array_key_exists($map[$key], $cols)) {
                return (string) $cols[$map[$key]];
            }
        }

        return '';
    }

    private function http(string $apiKey): PendingRequest
    {
        return Http::timeout(120)
            ->connectTimeout(15)
            ->withHeaders([
                'Authorization' => $apiKey,
                'Accept' => 'application/json',
            ]);
    }

    private function assertOk(int $status, string $label, mixed $json): void
    {
        if ($status === 204 || ($status >= 200 && $status < 300)) {
            return;
        }

        if (in_array($status, [401, 403], true)) {
            throw new RuntimeException('Нет доступа к данным Wildberries. Проверьте API-ключ кабинета.');
        }

        if ($status === 429) {
            throw new RuntimeException('Слишком много запросов к Wildberries. Подождите минуту и повторите.');
        }

        $detail = is_array($json) ? (string) ($json['detail'] ?? $json['message'] ?? $json['title'] ?? '') : '';
        throw new RuntimeException(
            $detail !== ''
                ? 'Не удалось загрузить '.$label.'.'
                : 'Не удалось загрузить '.$label.'. Попробуйте позже.'
        );
    }

    private function sleepMs(int $ms): void
    {
        if ($ms <= 0 || app()->environment('testing')) {
            return;
        }

        usleep($ms * 1000);
    }
}
