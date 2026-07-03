# WB Promo Calculator

## Права доступа

- Permission: `subscriber wb promo calculator`
- Middleware: `auth:api`, `verified`, `role:Подписчик`

## Назначение

Инструмент расчёта рентабельности акций WB и подготовки данных для репрайсера. Загружает Excel-отчёт по акциям WB, сопоставляет номенклатуры с данными ценообразования и считает маржу по каждой позиции акции.

## Ключевые файлы

- `app/Http/Controllers/Api/Subscriber/Wb/PromoCalculator/PromoCalculatorController.php`
- `app/Models/Subscribers/Wb/PriceCalculation/PriceCalculationV2Data.php`
- `app/Models/Subscribers/Wb/Repricer/RepricerCabinets.php`
- `app/Models/Subscribers/Wb/Repricer/RepricerSettings.php`

## Web routes (Inertia)

| Method | Route | Назначение |
| ------ | ----- | ---------- |
| GET | `/panel/wb/promocalculator` | Wizard-страница |
| POST | `/panel/wb/promocalculator/upload` | Загрузка xlsx (JSON) |
| POST | `/panel/wb/promocalculator/calculate` | Расчёт (JSON) |
| POST | `/panel/wb/promocalculator/export` | Экспорт xlsx (JSON, ссылка) |
| POST | `/panel/wb/promocalculator/repricer` | Отправка в репрайсер (JSON) |

Контроллер: `app/Http/Controllers/Web/Subscriber/Wb/PromoCalculator/PromoCalculatorController.php`.

## API эндпоинты

### `POST /subscriber/wb/promo-calculator/upload`

Загрузка `.xlsx` отчёта по акциям. Файл сохраняется в `storage/public/wb/promocalculator/`.

Тело: `file` (xlsx).

Ответ: `{ data: { file: "wb/promocalculator/{random}.xlsx" } }`.

### `POST /subscriber/wb/promo-calculator/calc`

Расчёт рентабельности акций.

Тело:

- `file` (required) — путь из upload
- `cabinet_id` (required) — кабинет ценообразования WB

Агрегирует `PriceCalculationV2Data` по `nm_id`: себестоимость, логистика, комиссии, `min_price_promo` и др.

### `POST /subscriber/wb/promo-calculator/xlsx`

Формирование Excel-отчёта по результатам расчёта.

### `POST /subscriber/wb/promo-calculator/repricer`

Передача номенклатур из расчёта в репрайсер (создание/обновление настроек `RepricerSettings`).

Тело `data[]` поддерживает именованные поля:

- `nm_id` (required)
- `plan_price` (required)

Legacy-формат с числовыми индексами (`[5]` — nmID, `[11]` — plan_price) также поддерживается.

## Технические детали

- Основа расчёта: агрегированные данные из `PriceCalculationV2Data` по `nm_id`.
- Используются показатели расходов и минимальной промо-цены из V2.
- Интеграция с [wb-repricer.md](wb-repricer.md) через bulk-создание настроек репрайсера.
