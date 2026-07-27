# WB Promo Calculator

## Права доступа

- Permission: `subscriber wb promo calculator`
- Middleware панели: `auth`, `verified`, `panel.access`, `wb.cabinets.migrated`, `permission:subscriber wb promo calculator`

## Назначение

Инструмент расчёта рентабельности акций WB и подготовки данных для репрайсера. Загружает Excel-отчёт по акциям WB, сопоставляет номенклатуры с данными ценообразования и считает маржу по каждой позиции акции.

Кабинеты — **единые** `wb_cabinets` ([wb-cabinets.md](wb-cabinets.md)). В wizard передаются списки одних и тех же кабинетов для выбора источника себестоимости и целевого репрайсера (`priceCalcCabinets` / `repricerCabinets` — фактически list summaries unified cabinets).

## Ключевые файлы

- `app/Http/Controllers/Web/Subscriber/Wb/PromoCalculator/PromoCalculatorController.php`
- `app/Services/Subscriber/Wb/WbPromoCalculatorService.php`
- `app/Http/Requests/Web/Subscriber/CalculatePromoCalculatorRequest.php`
- `app/Http/Requests/Web/Subscriber/SendPromoToRepricerRequest.php`
- `app/Models/Subscribers/Wb/WbCabinet.php`
- `app/Models/Subscribers/Wb/PriceCalculation/PriceCalculationV2Data.php` / V3 data (себестоимость)
- `app/Models/Subscribers/Wb/Repricer/RepricerSettings.php`

## Web routes (Inertia)

Prefix: `/panel/wb/promocalculator` · name: `subscriber.wb.promocalculator.*`

| Method | URL | Named route | Назначение |
|--------|-----|-------------|------------|
| GET | `/` | `index` | Wizard-страница |
| POST | `/upload` | `upload` | Загрузка xlsx (JSON) |
| POST | `/calculate` | `calculate` | Расчёт (JSON) |
| POST | `/export` | `export` | Экспорт xlsx (JSON, ссылка) |
| POST | `/repricer` | `repricer` | Отправка в репрайсер (JSON) |

Страница: `Subscriber/Wb/PromoCalculator/Index`.

Props index:

- `priceCalcCabinets` / `repricerCabinets` — summaries `wb_cabinets`
- `canUseRepricer` — permission check

## Контракт JSON

### POST `/panel/wb/promocalculator/upload`

Загрузка `.xlsx` отчёта по акциям. Файл в `storage/public/wb/promocalculator/`.

Тело: `file` (xlsx).

Ответ: `{ data: { file: "wb/promocalculator/{random}.xlsx" } }`.

### POST `/panel/wb/promocalculator/calculate`

Тело:

- `file` (required) — путь из upload
- `cabinet_id` (required) — id **unified** `wb_cabinets` (данные ценообразования с этим `cabinet_id`)

Ownership проверяется на Web-контроллере.

### POST `/panel/wb/promocalculator/export`

Формирование Excel-отчёта по результатам расчёта.

### POST `/panel/wb/promocalculator/repricer`

Передача номенклатур в репрайсер (создание/обновление `RepricerSettings` для `cabinet_id` = unified).

Тело `data[]`:

- `nm_id` (required)
- `plan_price` (required)

Legacy-формат с числовыми индексами (`[5]` — nmID, `[11]` — plan_price) также может поддерживаться в сервисе.

## Технические детали

- Основа расчёта: агрегированные данные ценообразования по `nm_id` для выбранного `cabinet_id`.
- Используются показатели расходов и минимальной промо-цены.
- Интеграция с [wb-repricer.md](wb-repricer.md) через bulk-создание настроек репрайсера на том же (или выбранном) unified cabinet.

## Связанные документы

- [wb-cabinets.md](wb-cabinets.md)
- [wb-price-calculation-v3.md](wb-price-calculation-v3.md)
- [wb-repricer.md](wb-repricer.md)
