# WB Promo Calculator

## Права доступа

- Permission: `subscriber wb promo calculator`
- Middleware панели: `auth`, `verified`, `panel.access`, `wb.cabinets.migrated`, `permission:subscriber wb promo calculator`

## Назначение

Инструмент расчёта рентабельности акций WB и подготовки данных для репрайсера. Загружает Excel-отчёт по акциям WB, сопоставляет номенклатуры с данными **ценообразования V3** активного кабинета и считает маржу по каждой позиции акции.

Кабинет — **единый** `wb_cabinets` ([wb-cabinets.md](wb-cabinets.md)). Выбор кабинета на странице **не нужен**: используется `users.selected_wb_cabinet_id` (переключатель WB в шапке). Тот же кабинет — источник себестоимости и цель отправки в репрайсер.

## Ключевые файлы

- `app/Http/Controllers/Web/Subscriber/Wb/PromoCalculator/PromoCalculatorController.php`
- Trait: `ResolvesSelectedWbCabinet`
- `app/Services/Subscriber/Wb/WbPromoCalculatorService.php`
- `app/Http/Requests/Web/Subscriber/CalculatePromoCalculatorRequest.php`
- `app/Http/Requests/Web/Subscriber/SendPromoToRepricerRequest.php`
- `app/Models/Subscribers/Wb/WbCabinet.php`
- `app/Models/Subscribers/Wb/PriceCalculation/PriceCalculationV3Data.php` (себестоимость)
- `app/Models/Subscribers/Wb/Repricer/RepricerSettings.php`

## Web routes (Inertia)

Prefix: `/panel/wb/promocalculator` · name: `subscriber.wb.promocalculator.*`

| Method | URL | Named route | Назначение |
|--------|-----|-------------|------------|
| GET | `/` | `index` | Страница инструмента |
| POST | `/upload` | `upload` | Загрузка xlsx (JSON) |
| POST | `/calculate` | `calculate` | Расчёт (JSON) |
| POST | `/export` | `export` | Экспорт xlsx (JSON, ссылка) |
| POST | `/repricer` | `repricer` | Отправка в репрайсер (JSON) |

Страница: `Subscriber/Wb/PromoCalculator/Index`.

При отсутствии выбранного кабинета — `Subscriber/Wb/Shared/NoCabinet`.

Props index:

- `cabinet` — `{ id, name }` активного `wb_cabinets`
- `canUseRepricer` — permission check

## Контракт JSON

### POST `/panel/wb/promocalculator/upload`

Загрузка `.xlsx` отчёта по акциям. Файл в `storage/public/wb/promocalculator/`.

Тело: `file` (xlsx).

Ответ: `{ data: { file: "wb/promocalculator/{random}.xlsx" } }`.

### POST `/panel/wb/promocalculator/calculate`

Тело:

- `file` (required) — путь из upload

`cabinet_id` **не передаётся**: берётся selected cabinet на сервере. Данные ценообразования — `wb_price_calc_v3_data` с этим `cabinet_id`.

Ownership обеспечивается resolve selected cabinet.

### POST `/panel/wb/promocalculator/export`

Формирование Excel-отчёта по результатам расчёта.

### POST `/panel/wb/promocalculator/repricer`

Передача номенклатур в репрайсер (создание/обновление `RepricerSettings` для selected `cabinet_id`).

Тело:

- `data[]`: `nm_id`, `plan_price`
- `dates`: `start`, `end` (datetime, МСК)

`cabinet_id` **не передаётся** — selected cabinet.

`terms` в TIME-стратегии — **массив периодов**. Из акций передаётся **разовый** интервал с датой:

```json
[{ "start": "2026-08-01 10:00:00", "end": "2026-08-07 22:00:00", "value": 1234 }]
```

Бот (`WbRepricerBot`) поддерживает оба формата:

- `Y-m-d H:i:s` — разовый период (акция);
- `H:i` — ежедневное окно.

`name` дополнительно: `Акция_{start}_по_{end}`.

Legacy-формат с числовыми индексами (`[5]` — nmID, `[11]` — plan_price) также может поддерживаться в сервисе.

## Технические детали

- Основа расчёта: агрегированные (AVG по `nm_id`) данные **V3** ценообразования для активного кабинета.
- Используются показатели расходов и минимальной промо-цены.
- Интеграция с [wb-repricer.md](wb-repricer.md) через bulk-создание настроек репрайсера на том же unified cabinet.

## Связанные документы

- [wb-cabinets.md](wb-cabinets.md)
- [wb-price-calculation-v3.md](wb-price-calculation-v3.md)
- [wb-repricer.md](wb-repricer.md)
