# WB Price Calculation V3

## Права доступа

- Permission: `subscriber wb price calculator`
- Middleware панели: `auth`, `verified`, `panel.access`, `wb.cabinets.migrated`, `permission:subscriber wb price calculator`

## Назначение

Инструмент V3 для работы с номенклатурой и Excel-формулами: синхронизация карточек WB, настройки, импорт/экспорт, расчёт стоп-цен и промо-цен.

Кабинет WB — **единый** ([wb-cabinets.md](wb-cabinets.md)). Workspace flat: `/panel/wb/price-calc` для **активного** кабинета. Все строки `wb_price_calc_v3_data.cabinet_id` = `wb_cabinets.id`.

Документ также фиксирует:

- таблицу данных в БД;
- соответствие колонок Excel (буквы) и полей БД;
- какие поля заполняются при импорте.

## Ключевые файлы

### Web (Inertia)

- `app/Http/Controllers/Web/Subscriber/Wb/PriceCalc/WorkspaceController.php`
- `app/Http/Controllers/Web/Subscriber/Wb/PriceCalc/CabinetsController.php`
- Trait: `ResolvesSelectedWbCabinet`

### Services / models / export

- `app/Services/Subscriber/Wb/WbPriceCalculationV3Service.php`
- `app/Services/Subscriber/Wb/WbPriceCalcCabinetsService.php`
- `app/Services/Wb/WbPriceCalculationService.php` (shared calc helpers, если используются)
- `app/Models/Subscribers/Wb/WbCabinet.php`
- `app/Models/Subscribers/Wb/PriceCalculation/PriceCalculationV3Data.php`
- `app/Models/Subscribers/Wb/PriceCalculation/PriceCalculationCabinets.php` — legacy `wb_price_cabinets` (миграция)
- `app/Models/Subscribers/Wb/PriceCalculation/PriceCalculationV2Settings.php`
- `app/Exports/Wb/PriceCalc/PriceCalcV3Export.php`

## Web routes (Inertia)

Prefix: `/panel/wb/price-calc` · name: `subscriber.wb.price-calc.*`

| Method | URL | Named route | Назначение |
|--------|-----|-------------|------------|
| GET | `/` | `index` | `Subscriber/Wb/PriceCalc/Cabinet/Show` |
| POST | `/sync` | `sync` | Синхронизация карточек WB |
| POST | `/settings` | `settings.save` | Сохранение настроек |
| POST | `/import-volume` | `import-volume` | Импорт объёмов (xlsx/zip) |
| POST | `/import-excel` | `import-excel` | Импорт пользовательских колонок |
| POST | `/export-excel` | `export-excel` | Экспорт xlsx |

Редирект legacy: `/cabinets/{cabinet}` → `/panel/wb/price-calc`.

При отсутствии выбранного кабинета — `Subscriber/Wb/Shared/NoCabinet`.

`cabinet_id` для всех операций берётся из selected cabinet на сервере.

## Таблица БД

- Таблица: `wb_price_calc_v3_data`
- Модель: `App\Models\Subscribers\Wb\PriceCalculation\PriceCalculationV3Data`
- FK: `cabinet_id` → `wb_cabinets.id` (legacy FK на `wb_price_cabinets` снят миграциями)

## Поля таблицы wb_price_calc_v3_data

- id: bigint, PK
- cabinet_id: bigint → `wb_cabinets.id`
- brand: string, nullable
- subject_name: string, nullable
- vendor_code: string, nullable
- size: string, nullable
- barcode: string, nullable
- nm_id: unsignedBigInteger, nullable
- volume_liters: decimal(10,3), nullable
- extra_liters: decimal(10,3), nullable
- cost_price: decimal(12,2), nullable
- margin_percent: decimal(6,2), nullable
- fulfillment_fee: decimal(12,2), nullable
- maintenance_percent: decimal(6,2), nullable
- stop_price: decimal(12,2), nullable
- avg_base_logistics: decimal(12,2), nullable
- avg_extra_liter_logistics: decimal(12,2), nullable
- localization_index: decimal(8,4), default 1
- avg_logistics: decimal(12,2), nullable
- reverse_logistics_cost_gt_1_0_l: decimal(12,2), nullable
- reverse_logistics_cost_0_801_1_0_l: decimal(12,2), nullable
- reverse_logistics_cost_0_601_0_8_l: decimal(12,2), nullable
- reverse_logistics_cost_0_401_0_6_l: decimal(12,2), nullable
- reverse_logistics_cost_0_201_0_4_l: decimal(12,2), nullable
- reverse_logistics_cost_0_001_0_2_l: decimal(12,2), nullable
- return_rate_gt_1_1_l: decimal(8,4), nullable
- return_rate_0_801_1_0_l: decimal(8,4), nullable
- return_rate_0_601_0_8_l: decimal(8,4), nullable
- return_rate_0_401_0_6_l: decimal(8,4), nullable
- return_rate_0_201_0_4_l: decimal(8,4), nullable
- return_rate_0_001_0_2_l: decimal(8,4), nullable
- return_cost: decimal(12,2), nullable
- buyout_percent: decimal(6,2), nullable
- total_logistics: decimal(12,2), nullable
- storage_cost: decimal(12,2), nullable
- sales_count: unsignedInteger, nullable
- storage_per_sale: decimal(12,2), nullable
- advertising_percent: decimal(6,2), nullable
- wb_commission_percent: decimal(6,2), nullable
- options_constructor_percent_sales: decimal(6,2), nullable
- options_constructor_percent_transfer: decimal(6,2), nullable
- acquiring_percent: decimal(6,2), nullable
- tax_percent: decimal(6,2), nullable
- maintenance_percent_sales: decimal(6,2), nullable
- irp: decimal(8,4), nullable
- commission_plus_acquiring: decimal(6,2), nullable
- standard_discount_percent: decimal(6,2), nullable
- promotion_percent: decimal(6,2), nullable
- min_price_promo: decimal(12,3), nullable
- standard_price: decimal(12,2), nullable
- price_before_discount: decimal(12,2), nullable
- deleted_at: timestamp, nullable
- created_at: timestamp
- updated_at: timestamp

## Excel маппинг (буква -> заголовок -> поле БД)

| Excel | Заголовок в файле                                                | Поле БД                              |
| ----- | ---------------------------------------------------------------- | ------------------------------------ |
| A     | Бренд                                                            | brand                                |
| B     | Предмет                                                          | subject_name                         |
| C     | Артикул продавца                                                 | vendor_code                          |
| D     | Артикул WB                                                       | nm_id                                |
| E     | Размер                                                           | size                                 |
| F     | Баркод                                                           | barcode                              |
| G     | Объем, л.                                                        | volume_liters                        |
| H     | Лишние свыше 1 литра                                             | extra_liters                         |
| I     | себес. руб.                                                      | cost_price                           |
| J     | маржа, %                                                         | margin_percent                       |
| K     | услуги фф руб./ед                                                | fulfillment_fee                      |
| L     | % за ведение (от суммы к перечислению на р/с)                    | maintenance_percent                  |
| M     | СТОП-ЦЕНА, руб. (если ведение считается от суммы к перечислению) | stop_price                           |
| N     | ср. ст-ть прямой логистики за 1 л                                | avg_base_logistics                   |
| O     | ср. ст-ть прямой логистики за доп. л                             | avg_extra_liter_logistics            |
| P     | ИЛ                                                               | localization_index                   |
| Q     | итог. ст-ть прямой логистики, руб.                               | avg_logistics                        |
| R     | ст-ть обр. логистики для каждого товара > 1 л                    | reverse_logistics_cost_gt_1_0_l      |
| S     | ст-ть обр. логистики для товаров 0,801-1,0 л                     | reverse_logistics_cost_0_801_1_0_l   |
| T     | ст-ть обр. логистики для товаров 0,601-0,8 л                     | reverse_logistics_cost_0_601_0_8_l   |
| U     | ст-ть обр. логистики для товаров 0,401-0,6 л                     | reverse_logistics_cost_0_401_0_6_l   |
| V     | ст-ть обр. логистики для товаров 0,201-0,4 л                     | reverse_logistics_cost_0_201_0_4_l   |
| W     | ст-ть обр. логистики для товаров 0,001-0,2 л                     | reverse_logistics_cost_0_001_0_2_l   |
| X     | ВОЗВРАТ >1.1 л.                                                  | return_rate_gt_1_1_l                 |
| Y     | ВОЗВРАТ 0,801-1л                                                 | return_rate_0_801_1_0_l              |
| Z     | ВОЗВРАТ 0,601-0,8л                                               | return_rate_0_601_0_8_l              |
| AA    | ВОЗВРАТ 0,401-0,6л                                               | return_rate_0_401_0_6_l              |
| AB    | ВОЗВРАТ 0,201-0,4л                                               | return_rate_0_201_0_4_l              |
| AC    | ВОЗВРАТ 0,001-0,2л                                               | return_rate_0_001_0_2_l              |
| AD    | Итог. ст-ть возврата                                             | return_cost                          |
| AE    | % ВЫКУПА                                                         | buyout_percent                       |
| AF    | ИТОГОВАЯ ЛОГИСТИКА, руб.                                         | total_logistics                      |
| AG    | хранение руб.                                                    | storage_cost                         |
| AH    | продажи, шт.                                                     | sales_count                          |
| AI    | хранение/1 продажа, руб.                                         | storage_per_sale                     |
| AJ    | ДРР, % от оборота                                                | advertising_percent                  |
| AK    | комиссия ВБ                                                      | wb_commission_percent                |
| AL    | % на опции в конструкторе тарифов, от суммы продажи              | options_constructor_percent_sales    |
| AM    | % на опции в конструкторе тарифов, от перечисления               | options_constructor_percent_transfer |
| AN    | эквайринг                                                        | acquiring_percent                    |
| AO    | налог, % от продажи                                              | tax_percent                          |
| AP    | % за ведение, если считается от суммы продажи                    | maintenance_percent_sales            |
| AQ    | ИРП                                                              | irp                                  |
| AR    | общий % с каждой проданной ед. на расходы WB                     | commission_plus_acquiring            |
| AS    | стандартная скидка для покупателя, %                             | standard_discount_percent            |
| AT    | % на участие в акции                                             | promotion_percent                    |
| AU    | MIN ЦЕНА ДЛЯ АКЦИЙ                                               | min_price_promo                      |
| AV    | ЦЕНА БЕЗ АКЦИИ                                                   | standard_price                       |
| AW    | ЦЕНА ДО СКИДКИ                                                   | price_before_discount                |

## Импорт Excel (какие поля обновляются)

Обновляются по заголовкам первой строки:

- cost_price
- margin_percent
- fulfillment_fee
- maintenance_percent
- advertising_percent
- wb_commission_percent (только при commission_source=manual)
- options_constructor_percent_sales (если нет опций или значение невалидно -> 0)
- options_constructor_percent_transfer (если нет опций или значение невалидно -> 0)
- acquiring_percent (только при acquiring_source=manual)
- tax_percent
- maintenance_percent_sales
- irp (только при use_irp=true)
- standard_discount_percent
- promotion_percent

Дополнительно:

- storage_cost обновляется только при use_storage=true.
- irp обновляется только при use_irp=true.
- В calculate поле commission_plus_acquiring (AR) считается как общий % расходов:
    - базовая формула: ДРР, % от оборота + комиссия ВБ + % на опции в конструкторе тарифов (от суммы продажи) + эквайринг + налог, % от продажи + % за ведение, если считается от суммы продажи + ИРП. (Excel: `=AJ + AK + AL + AN + AO + AP + AQ`)
    - для maintenance_type=transfer в блоке опций используется % на опции в конструкторе тарифов от перечисления вместо % от суммы продажи. (Excel: замена `AL` на `AM`)
    - % за ведение, если считается от суммы продажи, учитывается только при maintenance_type=sales. (Excel: компонент `+AP` применяется только для `sales`)
    - ИРП учитывается только при use_irp=true. (Excel: компонент `+AQ` применяется только при включенном `use_irp`)
- В calculate ценовой блок считается по формулам:
    - MIN ЦЕНА ДЛЯ АКЦИЙ (AU): (СТОП-ЦЕНА + ИТОГОВАЯ ЛОГИСТИКА + хранение/1 продажа + (% за ведение, если считается от суммы продажи / 100 * СТОП-ЦЕНА)) / ((100 - общий % с каждой проданной ед. на расходы WB) / 100). (Excel: `=(M + AF + AI + (AP / 100 * M)) / ((100 - AR) / 100)`, компонент `AP` применяется только при `maintenance_type=sales`, иначе считается `0`)
    - ЦЕНА БЕЗ АКЦИИ (AV): MIN ЦЕНА ДЛЯ АКЦИЙ / ((100 - % на участие в акции) / 100). (Excel: `=AU / ((100 - AT) / 100)`)
    - ЦЕНА ДО СКИДКИ (AW): ЦЕНА БЕЗ АКЦИИ / ((100 - стандартная скидка для покупателя, %) / 100), без округления вверх. (Excel: `=AV / ((100 - AS) / 100)`)

Идентификация строки при импорте:

- hide_sizes=true: по колонке Артикул WB (nm_id)
- hide_sizes=false: по колонке Баркод (barcode)

Импорт объёма (`POST /panel/wb/price-calc/import-volume`):

- Поддерживает `.xlsx` или `.zip`.
- Если загружен `.zip`, внутри архива должен быть ровно один файл `.xlsx`.
- Колонки: `Баркод` и `Объем, л.`.

## Внешние WB API (что используем и что забираем)

### 1) Карточки товаров для sync

- Endpoint: `POST https://content-api.wildberries.ru/content/v2/get/cards/list?locale=ru`
- Где используется: sync карточек (`POST /panel/wb/price-calc/sync`)
- Что забираем: `cards[]` (brand, subjectName, vendorCode, nmID, sizes.wbSize, sizes.skus), cursor pagination

### 2) Продажи по складам

- Endpoint: `GET https://statistics-api.wildberries.ru/api/v1/supplier/sales`
- Блок средней логистики: `warehouseName`, `warehouseType` (только `Склад WB`), `lastChangeDate`

### 3) Тарифы логистики

- Endpoint: `GET https://common-api.wildberries.ru/api/v1/tariffs/box`
- `warehouseList[].warehouseName`, `boxDeliveryBase`, `boxDeliveryLiter`

### 4) Комиссии WB

- Endpoint: `GET https://common-api.wildberries.ru/api/v1/tariffs/commission?locale=ru`
- `report[].subjectName`, `kgvpMarketplace` (fbs), `paidStorageKgvp` (fbo)

### 5) Финансовый отчёт

- Endpoint: `POST https://finance-api.wildberries.ru/api/finance/v1/sales-reports/detailed`
- При `commission_source=reports` или `acquiring_source=reports`: `sellerOperName`, `commissionPercent`, `acquiringPercent`

### 6) Воронка продаж

- Endpoint: `POST https://seller-analytics-api.wildberries.ru/api/analytics/v3/sales-funnel/products`
- `buyout_percent` / `sales_count` по nmId

## Правила экспорта Excel

- Колонки, которые заполняет пользователь, подсвечиваются жёлтым цветом.
- Состав экспортируемых пользовательских колонок зависит от настроек кабинета:
    - `use_storage=false`: колонка хранения (`storage_cost`) не выгружается;
    - `commission_source!=manual`: колонка комиссии WB (`wb_commission_percent`) не выгружается;
    - `acquiring_source!=manual`: колонка эквайринга (`acquiring_percent`) не выгружается;
    - `maintenance_type=sales`: выгружается только колонка опций от суммы продажи (`options_constructor_percent_sales`);
    - `maintenance_type=transfer`: выгружается только колонка опций от перечисления (`options_constructor_percent_transfer`), колонки sales-опций и `maintenance_percent_sales` не выгружаются;
    - `use_irp=false`: колонка ИРП (`irp`) не выгружается.

## Связанные документы

- [wb-cabinets.md](wb-cabinets.md)
- [wb-profitability.md](wb-profitability.md) — использует V3 себестоимость
- [wb-promo-calculator.md](wb-promo-calculator.md)
