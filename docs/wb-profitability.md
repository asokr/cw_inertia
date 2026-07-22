# WB Profitability

## Права доступа

- Permission: `subscriber wb profitability`
- Middleware: `auth:api`, `verified`, `role:Подписчик`

## Назначение

Инструмент расчёта рентабельности по операциям Wildberries.

Инструмент получает детализированный финансовый отчёт из WB за период, считает агрегаты и маржу по операциям, сохраняет результат в БД, отдаёт данные для таблицы/виджета и поддерживает экспорт в Excel.

## Web routes (Inertia)

- Permission: `subscriber wb profitability`
- Middleware: `auth`, `verified`, `role:Подписчик`

| Method | URL | Named route | Inertia page |
| --- | --- | --- | --- |
| GET | `/panel/wb/profitability` | `subscriber.wb.profitability.index` | `Subscriber/Wb/Profitability/Index` |
| POST | `/panel/wb/profitability/cabinets` | `subscriber.wb.profitability.cabinets.store` | — |
| PUT | `/panel/wb/profitability/cabinets/{cabinet}` | `subscriber.wb.profitability.cabinets.update` | — |
| DELETE | `/panel/wb/profitability/cabinets/{cabinet}` | `subscriber.wb.profitability.cabinets.destroy` | — |
| GET | `/panel/wb/profitability/cabinets/{cabinet}` | `subscriber.wb.profitability.cabinets.show` | `Subscriber/Wb/Profitability/Cabinet/Show` |
| POST | `/panel/wb/profitability/cabinets/{cabinet}/report` | `subscriber.wb.profitability.cabinets.report.store` | — |
| GET | `/panel/wb/profitability/cabinets/{cabinet}/export` | `subscriber.wb.profitability.cabinets.export` | binary XLSX |

Web-контроллеры:

- `app/Http/Controllers/Web/Subscriber/Wb/Profitability/CabinetsController.php`
- `app/Http/Controllers/Web/Subscriber/Wb/Profitability/ReportController.php`

## Ключевые файлы

- `app/Http/Controllers/Api/Subscriber/Wb/Profitability/ProfitabilityController.php`
- `app/Http/Controllers/Api/Subscriber/Wb/Profitability/ProfitabilityCabinetsController.php`
- `app/Jobs/ProcessProfitabilityReport.php`
- `app/Console/Commands/ResetStuckProfitabilityReportsCommand.php`
- `app/Services/Wb/ProfitabilityApiService.php`
- `app/Http/Traits/GuzzleTrait.php`
- `app/Console/Kernel.php`
- `app/Models/Subscribers/Wb/Profitability/ProfitabilityCabinet.php`
- `app/Models/Subscribers/Wb/Profitability/Report.php`
- `app/Models/Subscribers/Wb/Profitability/Item.php`
- `app/Exports/ProfitabilityReportExport.php`

## Эндпоинты

### Кабинеты

- `GET /subscriber/wb/profitability/cabinets`
- `POST /subscriber/wb/profitability/cabinets`
- `GET /subscriber/wb/profitability/cabinets/{id}`
- `PUT/PATCH /subscriber/wb/profitability/cabinets/{id}`
- `DELETE /subscriber/wb/profitability/cabinets/{id}`

### Отчёт

- `POST /subscriber/wb/profitability`
- `GET /subscriber/wb/profitability/{cabinet}`
- `GET /subscriber/wb/profitability/status/{cabinet}`
- `GET /subscriber/wb/profitability/{cabinet}/export`
- `GET /subscriber/wb/profitability/{cabinet}/widget`

## Контракт API (внутренний)

### 1) POST /subscriber/wb/profitability

Назначение: поставить пересчёт отчёта в очередь `profitability`.

Тело запроса:

- `cabinet_id` (required, exists: `wb_profitability_cabinets.id`)
- `date_from` (required, date)
- `date_to` (required, date, `after_or_equal:date_from`)
- `dop_rashod` (optional, numeric, min:0) — общая сумма дополнительного расхода в рублях за период; распределяется только по операциям `Продажа` пропорционально сумме продажи (`sum_to_transfer`)
- `nalog_percent` (optional, numeric, min:0, max:100) — ставка налога в процентах; налог по строке продажи считается от `retailAmount`

Успешный ответ:

```json
{
    "success": true,
    "messages": ["Обновление поставлено в очередь"]
}
```

Ошибки валидации:

```json
{
    "success": false,
    "messages": ["..."]
}
```

### 2) GET /subscriber/wb/profitability/status/{cabinet}

Назначение: вернуть текущий статус последней job по кабинету.

Успешный ответ:

```json
{
    "success": true,
    "messages": ["Статус обработки"],
    "data": {
        "status": "processing|done|failed",
        "error": null
    }
}
```

### 3) GET /subscriber/wb/profitability/{cabinet}

Назначение: вернуть сохранённый отчёт и позиции, сгруппированные по `supplier_oper_name`.

Ключевая структура `data`:

- `status` / `error` из `job_statuses`
- `report`: агрегаты из `wb_profitability_reports`
- `items`: массив групп:
    - `supplier_oper_name`
    - `items[]` c полями: `nm_id`, `sa_name`, `size`, `barcode`, `warehouse`, `reasoning`, `quantity`, `sum_to_transfer`, `purchase_cost`, `logistics`, `cost_adjustments`, `cashback`, `dop_rashod`, `nalog`, `margin`, `profitability_percent`

Дополнительно в `report` возвращаются:

- `cashback` — суммарное значение `cashbackAmount + cashbackDiscount` по продажам
- `dop_rashod` — общая сумма доп.расхода, использованная в расчёте отчёта
- `nalog` — суммарный налог по продажам
- `nalog_percent` — ставка налога в процентах, использованная в расчёте отчёта

### 4) GET /subscriber/wb/profitability/{cabinet}/widget

Назначение: сжатые данные для виджета + ТОПы товаров.

Дополнительно возвращает:

- `top_profitable_products[]`
- `top_low_margin_products[]`

Для этих массивов подгружается `image` (кеш 7 дней).

### 5) GET /subscriber/wb/profitability/{cabinet}/export

Назначение: выгрузка `.xlsx` на основе последнего сохранённого отчёта.

Готовый файл переиспользуется только если совпадают `report_id` **и** `report_updated_at` (fingerprint версии отчёта). На кабинет один report row (`updateOrCreate` по `cabinet_id`), поэтому одного `report_id` недостаточно. После успешного пересчёта `ProcessProfitabilityReport` сбрасывает export-cache и удаляет старый файл.

## Какие WB API используются

### Основной endpoint WB Finance API

- `POST https://finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed`

`GET https://statistics-api.wildberries.ru/api/v5/supplier/reportDetailByPeriod` — deprecated.

Параметры запроса:

- `dateFrom` (`Y-m-d`)
- `dateTo` (`Y-m-d`)
- `limit` (`100000`)
- `rrdId` (для пагинации/дочитки больших ответов)
- `period` (`daily`)
- `fields` (массив полей, которые требуются для расчёта)

Авторизация:

- Заголовок `Authorization: <apikey кабинета>`

Коды, которые обрабатываются явно:

- `200` — данные получены
- `204` — пустой ответ (операций нет)
- `400`, `401`, `403`, `422` — ошибка запроса/авторизации/валидации на стороне WB
- `429` — rate limit, выполняются ретраи (до 3 попыток) и пауза

Поля ответа WB, используемые в расчёте (legacy -> новый контракт):

- `rrd_id` -> `rrdId`
- `supplier_oper_name` -> `sellerOperName`
- `ppvz_for_pay` -> `forPay`
- `retail_amount` -> `retailAmount`
- `quantity` -> `quantity`
- `cashback_amount` -> `cashbackAmount`
- `cashback_discount` -> `cashbackDiscount`
- `delivery_rub` -> `deliveryService`
- `bonus_type_name` -> `bonusTypeName`
- `acceptance` -> `paidAcceptance`
- `penalty` -> `penalty`
- `deduction` -> `deduction`
- `storage_fee` -> `paidStorage`
- `doc_type_name` -> `docTypeName`
- `nm_id` -> `nmId`
- `sa_name` -> `vendorCode`
- `ts_name` -> `techSize`
- `barcode` -> `sku`
- `office_name` -> `officeName`

### Endpoint для картинок карточек (для виджета)

Картинка формируется по шаблону URL из `config/wbConstants.php`:

- `https://basket-%s.wbbasket.ru/vol%s/part%s/%s/images/c246x328/%s.webp`

Это публичная выдача статики WB (без авторизации).

## Внутренняя логика расчёта

- Job собирает операции и агрегаты по периодам: продажи, возвраты, логистика, хранение, штрафы, удержания, приёмка, корректировки.
- Себестоимость для продаж/возвратов берётся только из `wb_price_calc_v3_data`.
- Матчинг себестоимости: сначала по `barcode`, затем по `nm_id`.
- Подбор релевантных кабинетов ценообразования: сначала матч по `apikey`/`name`, затем fallback на все кабинеты пользователя.
- Для продаж считается итоговая маржа и `profitability_percent`.
- Для операций `Продажа` маржа уменьшается на:
    - логистику (`logistics`)
    - корректировки (`cost_adjustments`), где хранение распределяется пропорционально сумме продажи
    - `cashback` (сумма `cashbackAmount + cashbackDiscount`)
    - долю пользовательского доп.расхода `dop_rashod`, распределённую пропорционально сумме продажи строки и сохранённую в `items[].dop_rashod`
    - `nalog`, рассчитанный от `retailAmount` по ставке `nalog_percent` и сохранённый в `items[].nalog`
- Результат сохраняется в:
    - `wb_profitability_reports` (агрегаты)
    - `wb_profitability_items` (детализация)
- Статус выполнения хранится в `job_statuses` (`processing|done|failed`).

## Job и очередь

### ProcessProfitabilityReport

Файл: `app/Jobs/ProcessProfitabilityReport.php`

Что делает:

- Вызывает WB endpoint `sales-reports/detailed`
- Обрабатывает пагинацию через `rrdId`
- Пишет расчёт в БД
- Сбрасывает кеш отчёта/виджета
- Обновляет статус в `job_statuses`

Параметры dispatch:

- `cabinetId`
- `dateFrom`
- `dateTo`
- `userId`
- `dopRashod`
- `nalogPercent`

Очередь: `profitability`

Ограничения job:

- `timeout = 1800` (30 минут)
- `tries = 1`

### ResetStuckProfitabilityReportsCommand

Файл: `app/Console/Commands/ResetStuckProfitabilityReportsCommand.php`

Назначение: переводит «зависшие» записи `job_statuses` из `processing` в `failed`.

Команда:

- `php artisan subscriber:fail-stuck-profitability-reports --minutes=35`

Где используется:

- В расписании (`app/Console/Kernel.php`) каждые 5 минут.

## Как запускать инструмент

### 1) Запуск воркера очереди profitability

```bash
php artisan queue:work --queue=profitability --tries=1 --timeout=1800
```

Рекомендуется запускать через supervisor/pm2/service manager, чтобы воркер был постоянным.

### 2) Поставить отчёт в очередь

Через API:

- `POST /subscriber/wb/profitability` с `cabinet_id`, `date_from`, `date_to`.

### 3) Проверять статус

- `GET /subscriber/wb/profitability/status/{cabinet}`

### 4) Получить результат

- `GET /subscriber/wb/profitability/{cabinet}`
- `GET /subscriber/wb/profitability/{cabinet}/widget`
- `GET /subscriber/wb/profitability/{cabinet}/export`

## Примечания по стабильности

- Между запросами к `sales-reports/detailed` выдерживается троттлинг (минимальный интервал).
- При `429` включены ретраи с паузой.
- При `401` с признаком `access token expired` задача помечается как `failed`, пользователю отправляется уведомление о необходимости обновить токен.
- Для `show/widget` используется кеш; после пересчёта кеш сбрасывается.

## Структура БД (дополнения)

- `wb_profitability_items.cashback` (`decimal(12,2)`, default `0`) — cashback по строке операции.
- `wb_profitability_items.dop_rashod` (`decimal(12,2)`, default `0`) — распределённая доля доп.расхода по строке продажи.
- `wb_profitability_items.nalog` (`decimal(12,2)`, default `0`) — налог по строке продажи, рассчитанный от `retailAmount`.
- `wb_profitability_reports.cashback` (`decimal(12,2)`, default `0`) — суммарный cashback за период.
- `wb_profitability_reports.dop_rashod` (`decimal(12,2)`, default `0`) — общая сумма доп.расхода, переданная при запуске расчёта.
- `wb_profitability_reports.nalog` (`decimal(12,2)`, default `0`) — суммарный налог за период.
- `wb_profitability_reports.nalog_percent` (`decimal(5,2)`, default `0`) — ставка налога, применённая в расчёте.

## Технические детали

- Обработка отчёта выполняется фоново через job.
- Формируется агрегированный отчёт и детализация по операциям.
- Поддерживается выгрузка в Excel.
- В `ProcessProfitabilityReport` себестоимость для продаж/возвратов синхронизируется только из `wb_price_calc_v3_data`.
- Матчинг себестоимости выполняется сначала по `barcode`, затем по `nm_id`; для выбора кабинета сначала используются совпадения по `apikey`/`name`, затем fallback на все кабинеты ценообразования пользователя.
