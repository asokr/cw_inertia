# WB Profitability

## Права доступа

- Permission: `subscriber wb profitability`
- Middleware панели: `auth`, `verified`, `panel.access`, `wb.cabinets.migrated`, `permission:subscriber wb profitability`

## Назначение

Инструмент расчёта рентабельности по операциям Wildberries.

Инструмент получает детализированный финансовый отчёт из WB за период, считает агрегаты и маржу по операциям, сохраняет результат в БД, отдаёт данные для таблицы/виджета и поддерживает экспорт в Excel.

Кабинет WB — **единый** ([wb-cabinets.md](wb-cabinets.md)). Workspace без списка кабинетов: данные для **активного** кабинета из шапки. `cabinet_id` в reports/items = `wb_cabinets.id`.

## Web routes (Inertia)

Prefix: `/panel/wb/profitability` · name: `subscriber.wb.profitability.*`

| Method | URL | Named route | Ответ |
|--------|-----|-------------|--------|
| GET | `/` | `index` | `Subscriber/Wb/Profitability/Cabinet/Show` |
| GET | `/items` | `items` | JSON: страница items по группам |
| POST | `/report` | `report.store` | Поставить пересчёт в очередь |
| POST | `/export` | `export.start` | Старт экспорта XLSX |
| GET | `/export/status` | `export.status` | Статус экспорта |
| GET | `/export/download` | `export.download` | Скачивание файла |

Редирект legacy: `/cabinets/{cabinet}` → `/panel/wb/profitability`.

Web-контроллеры:

- `app/Http/Controllers/Web/Subscriber/Wb/Profitability/ReportController.php`
- `app/Http/Controllers/Web/Subscriber/Wb/Profitability/CabinetsController.php` (legacy helpers / admin-adjacent при наличии)

При отсутствии выбранного кабинета — `Subscriber/Wb/Shared/NoCabinet`.

## Ключевые файлы

- `app/Services/Subscriber/Wb/WbProfitabilityReportService.php`
- `app/Services/Subscriber/Wb/WbProfitabilityCabinetsService.php`
- `app/Jobs/ProcessProfitabilityReport.php` — резолвит `WbCabinet::findOrFail`
- `app/Console/Commands/ResetStuckProfitabilityReportsCommand.php`
- `app/Services/Wb/ProfitabilityApiService.php`
- `app/Http/Traits/GuzzleTrait.php`
- `app/Models/Subscribers/Wb/WbCabinet.php`
- `app/Models/Subscribers/Wb/Profitability/ProfitabilityCabinet.php` — legacy (миграция)
- `app/Models/Subscribers/Wb/Profitability/Report.php`
- `app/Models/Subscribers/Wb/Profitability/Item.php`
- `app/Exports/ProfitabilityReportExport.php`

## Контракт workspace (внутренний)

### POST `/panel/wb/profitability/report`

Назначение: поставить пересчёт отчёта в очередь `profitability` для **selected** cabinet.

Тело запроса:

- `date_from` (required, date)
- `date_to` (required, date, `after_or_equal:date_from`)
- `dop_rashod` (optional, numeric, min:0) — доп. расход в рублях; распределяется только по операциям `Продажа` пропорционально `sum_to_transfer`
- `nalog_percent` (optional, numeric, min:0, max:100) — налог % от `retailAmount`

`cabinet_id` берётся из selected cabinet на сервере (не из body path).

### GET `/panel/wb/profitability/items`

Пагинированная/группированная детализация для таблицы UI.

### Export flow

1. `POST /export` — старт
2. `GET /export/status` — polling
3. `GET /export/download` — файл

Готовый файл переиспользуется только если совпадают `report_id` **и** `report_updated_at` (fingerprint). На кабинет один report row (`updateOrCreate` по `cabinet_id`). После успешного пересчёта `ProcessProfitabilityReport` сбрасывает export-cache.

### Данные страницы show

- `status` / `error` из `job_statuses`
- `report`: агрегаты из `wb_profitability_reports`
- `widget`: сжатые данные + ТОПы
- `groupMeta` + lazy `items` URL
- В `report`: `cashback`, `dop_rashod`, `nalog`, `nalog_percent`

## Какие WB API используются

### Основной endpoint WB Finance API

- `POST https://finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed`

`GET https://statistics-api.wildberries.ru/api/v5/supplier/reportDetailByPeriod` — deprecated.

Параметры: `dateFrom`, `dateTo`, `limit` (100000), `rrdId`, `period` (`daily`), `fields`.

Авторизация: `Authorization: <apikey кабинета>` из `wb_cabinets`.

Коды: `200`, `204`, `400/401/403/422`, `429` (ретраи).

Поля ответа WB (legacy → новый контракт):

- `rrd_id` → `rrdId`
- `supplier_oper_name` → `sellerOperName`
- `ppvz_for_pay` → `forPay`
- `retail_amount` → `retailAmount`
- `quantity` → `quantity`
- `cashback_amount` / `cashback_discount` → `cashbackAmount` / `cashbackDiscount`
- `delivery_rub` → `deliveryService`
- `bonus_type_name` → `bonusTypeName`
- `acceptance` → `paidAcceptance`
- `penalty` / `deduction` / `storage_fee` → `penalty` / `deduction` / `paidStorage`
- `doc_type_name` → `docTypeName`
- `nm_id` → `nmId`
- `sa_name` → `vendorCode`
- `ts_name` → `techSize`
- `barcode` → `sku`
- `office_name` → `officeName`

### Картинки для виджета

Шаблон из `config/wbConstants.php` (публичная статика WB).

## Внутренняя логика расчёта

- Job собирает операции и агрегаты: продажи, возвраты, логистика, хранение, штрафы, удержания, приёмка, корректировки.
- Себестоимость для продаж/возвратов — из `wb_price_calc_v3_data`.
- Матчинг себестоимости: сначала `barcode`, затем `nm_id`.
- **Unified cabinet:** price-calc data шарит тот же `cabinet_id` (`wb_cabinets.id`) — отдельный матч по apikey/name legacy-кабинетов больше не нужен для новых данных.
- Для продаж маржа уменьшается на логистику, корректировки, cashback, долю `dop_rashod`, `nalog`.
- Результат: `wb_profitability_reports` + `wb_profitability_items`.
- Статус: `job_statuses` (`processing|done|failed`).

## Job и очередь

### ProcessProfitabilityReport

Файл: `app/Jobs/ProcessProfitabilityReport.php`

Параметры dispatch: `cabinetId` (wb_cabinets.id), `dateFrom`, `dateTo`, `userId`, `dopRashod`, `nalogPercent`.

Очередь: `profitability` · `timeout = 1800` · `tries = 1`.

При `401` access token expired — `failed` + `WbCabinetAuthorizationNotification`.

### ResetStuckProfitabilityReportsCommand

```bash
php artisan subscriber:fail-stuck-profitability-reports --minutes=35
```

В `Kernel` — каждые 5 минут.

## Как запускать

```bash
php artisan queue:work --queue=profitability --tries=1 --timeout=1800
```

UI: `/panel/wb/profitability` → форма периода → `POST /report` → polling job status на странице.

## Структура БД (дополнения)

- `wb_profitability_items.cashback`, `.dop_rashod`, `.nalog`
- `wb_profitability_reports.cashback`, `.dop_rashod`, `.nalog`, `.nalog_percent`

## Связанные документы

- [wb-cabinets.md](wb-cabinets.md)
- [wb-price-calculation-v3.md](wb-price-calculation-v3.md) — источник себестоимости
