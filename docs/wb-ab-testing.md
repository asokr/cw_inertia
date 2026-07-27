# WB A/B-тестирование

## Права доступа

- Permission: `subscriber wb ab testing`
- Middleware панели: `auth`, `verified`, `panel.access`, `wb.cabinets.migrated`, `permission:subscriber wb ab testing`

## Назначение

Инструмент для A/B-тестирования **главной фотографии** карточки товара Wildberries.

Кабинет WB — **единый** ([wb-cabinets.md](wb-cabinets.md)). Workspace flat: `/panel/wb/ab-testing` для **активного** кабинета.

### Текущий scope (MVP)

- Фундамент инструмента в панели
- Многошаговый wizard (6 шагов)
- **Шаг 1 — Выбор товара:** таблица номенклатуры, поиск, выбор одного товара, переход ко шагу 2

### Пока не реализовано

- Загрузка фотографий эксперимента
- Настройки, реклама WB, смена фото, сбор статистики, результаты
- Таблица экспериментов в БД (статус/дата в UI — заглушки: «Не создан» / «—»)

## Ключевые файлы

### Web (Inertia)

- `app/Http/Controllers/Web/Subscriber/Wb/AbTesting/WorkspaceController.php`
- Trait: `ResolvesSelectedWbCabinet`

### Services / models

- `app/Services/Subscriber/Wb/WbAbTestingService.php`
- `app/Models/Subscribers/Wb/AbTesting/AbProduct.php`
- `app/Enums/WbAbTestStatus.php`
- `app/Services/Wb/WbPriceCalculationService.php` — Content API `getAllCards`

### UI

- `resources/js/Pages/Subscriber/Wb/AbTesting/Index.vue`
- `resources/js/components/subscriber/wb/ab-testing/*`

## Web routes

Prefix: `/panel/wb/ab-testing` · name: `subscriber.wb.ab-testing.*`

| Method | URL | Named route | Назначение |
|--------|-----|-------------|------------|
| GET | `/` | `index` | Wizard + список товаров |
| POST | `/sync` | `sync` | Синхронизация номенклатуры из WB |

При отсутствии выбранного кабинета — `Subscriber/Wb/Shared/NoCabinet`.

## Кэш товаров

Таблица: `wb_ab_products`  
Модель: `App\Models\Subscribers\Wb\AbTesting\AbProduct`  
FK: `cabinet_id` → `wb_cabinets.id`

| Поле | Описание |
|------|----------|
| nm_id | Артикул WB |
| vendor_code | Артикул продавца |
| title | Название |
| brand | Бренд |
| subject_name | Категория (предмет) |
| photo_url | Превью из Content API (`photos[0]`: c246x328 → square → tm → big) |
| price | Цена (колонка в UI; наполнение API — позже) |
| rating | Рейтинг (пока null) |

Синхронизация: Content API `/content/v2/get/cards/list` через `WbPriceCalculationService::getAllCards`  
(title, brand, subjectName, vendorCode, photos → photo_url).  

Поиск в UI: `nm_id`, `vendor_code`.

## Wizard

| Шаг | Название | Статус |
|-----|----------|--------|
| 1 | Выбор товара | Реализован |
| 2 | Фотографии | Заглушка |
| 3 | Настройки | Заглушка |
| 4 | Создание рекламы | Заглушка |
| 5 | Тестирование | Заглушка |
| 6 | Результаты | Заглушка |

Статусы эксперимента (для UI; хранение в БД — позже):

- Не создан
- Черновик
- В процессе
- Завершён
- Ошибка
