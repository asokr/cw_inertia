# Ozon A/B-тестирование

## Права доступа

- Permission: `subscriber oz ab testing`
- Middleware панели: `auth`, `verified`, `panel.access`, `wb.cabinets.migrated`, `permission:subscriber oz ab testing`

## Назначение

Инструмент для A/B-тестирования **главной фотографии** карточки товара Ozon. По сценарию как у [WB A/B](wb-ab-testing.md): товар → эксперимент → рекламная кампания → варианты фото → циклы → победитель.

Кабинет Ozon — единый ([oz-cabinets.md](oz-cabinets.md)). Workspace: `/panel/oz/ab-testing`.

Для работы с рекламой нужны ключи **Performance API** (`performance_client_id` + `performance_client_secret` в кабинете). Seller API-ключ рекламу не открывает.

### Scope (MVP)

- Товары → эксперименты товара → workspace
- Одновременно `running` — не больше одного на товар и не больше одного на кампанию
- Фото: 2–6, JPEG/PNG, **public disk** (Ozon принимает только публичные URL)
- Цикл: минимум и по умолчанию **30 минут** (или раньше, если набраны показы за круг)
- Статистика эксперимента — **дельта** относительно снимка на старте круга
- Показы: **один sync-запрос на кабинет** раз в ~60 секунд (`ProcessOzAbCabinetTickJob`). В запрос входят все running-кампании кабинета (до 10 id). Новый эксперимент не поднимает отдельную цепочку — попадает в уже идущий тик.
- Fallback-тик раз в 2 минуты, если цепочка job оборвалась.
- Движок: queue `oz_ab_testing` + fallback `subscriber:oz-ab-testing-tick`

## Ключевые файлы

- `app/Http/Controllers/Web/Subscriber/Oz/AbTesting/WorkspaceController.php`
- `app/Services/Subscriber/Oz/OzAbTestingService.php`
- `app/Services/Subscriber/Oz/AbTesting/OzAbExperimentEngine.php`
- `app/Services/Ozon/OzonPerformanceApiService.php`
- `app/Services/Ozon/OzonApiService.php` — `pictures/import`, `pictures/info`
- Models: `App\Models\Subscribers\Oz\AbTesting\*`
- Job: `ProcessOzAbCabinetTickJob` (тик кабинета). `ProcessOzAbExperimentJob` — только совместимость со старой очередью.
- Command: `subscriber:oz-ab-testing-tick`

UI: `resources/js/Pages/Subscriber/Oz/AbTesting/Index.vue`, `resources/js/components/subscriber/oz/ab-testing/*`

## Web routes

Prefix: `/panel/oz/ab-testing` · name: `subscriber.oz.ab-testing.*`

| Method | URL | Назначение |
|--------|-----|------------|
| GET | `/` | Workspace |
| POST | `/sync` | Синхронизация товаров Seller API |
| POST | `/experiments` | Черновик эксперимента |
| PATCH | `/experiments/{id}` | Переименовать |
| PATCH | `/experiments/{id}/settings` | Настройки |
| POST | `/experiments/{id}/start` | Запуск |
| POST | `/experiments/{id}/stop` | Стоп |
| GET | `/campaigns?experiment_id=` | Доступные РК кабинета |
| POST | `/campaigns` | Создать CPC-кампанию и привязать |
| POST | `/campaigns/{id}/prepare` | Добавить SKU при необходимости и привязать |
| POST | `/campaigns/{id}/pause` | Остановить РК |
| DELETE | `/campaigns/{id}` | Удалить из инструмента (только созданные им) |
| GET/POST/DELETE/PATCH | `/experiments/{id}/photos*` | Фото |
| GET | `/media/{photo}` | Превью |

## Реклама (Performance API)

Документация: https://docs.ozon.ru/api/performance/  
Host: `https://api-performance.ozon.ru`

Показываем кампании `advObjectType = SKU` в статусах `CAMPAIGN_STATE_RUNNING` и `CAMPAIGN_STATE_INACTIVE`.  
Не показываем баннеры, видеобаннеры, оплату за заказ, архив, модерацию, автостратегию.

Создание: `POST /api/client/campaign/cpc/v2/product`.  
Добавление товара: `POST /api/client/campaign/{id}/products` (только add).  
Старт/стоп: `activate` / `deactivate`.

Статистика (тик кабинета ~60 сек): один `POST /api/client/statistics/products/sku` на кабинет, `campaignIds` = все running-кампании (пачки по 10). Baseline на старте эксперимента — отдельный снимок этой кампании, чтобы открыть цикл.

Baseline: `views_start` / `clicks_start` в цикле. CTR эксперимента = дельта, не история кампании.

Пополнение рекламного баланса из инструмента **нет** (в API нет аналога WB deposit).

## Товары (Seller API)

Синхронизация: `POST /v3/product/list` + `POST /v3/product/info/list`.

В `product/info/list` поле `primary_image` приходит **массивом URL**, не строкой. Синк берёт первый URL (то же для `images`). SKU — из `sources[]`, иначе `sku` / `fbs_sku` / `fbo_sku`.

Каждый размер на Ozon — отдельный `product_id`. В списке A/B показываем **товар**, SKU свёрнуты: клик по карточке раскрывает размеры, клик по SKU открывает эксперименты. Группировка: `model_info.model_id`, иначе название. Пагинация — 25 товаров на страницу, не 25 SKU. Колонок бренда и цены нет — этих полей нет в этом запросе.

## Фото карточки (Seller API)

`POST /v1/product/pictures/import` затирает **весь** набор изображений и требует публичные URL.

Перед стартом сохраняем `gallery_snapshot` (images, 360, цвет). Смена варианта = новое главное + остальные из снимка. На stop возвращаем исходную галерею; на complete ставим победителя.

Статус загрузки: `POST /v2/product/pictures/info`.

## Очередь

```
php artisan queue:work --queue=oz_ab_testing,default
```

Основной цикл: job сам ставит следующий тик через **60 секунд**.  
Fallback: `subscriber:oz-ab-testing-tick` каждые 2 минуты.

## Связанные документы

- [oz-cabinets.md](oz-cabinets.md)
- [wb-ab-testing.md](wb-ab-testing.md)
- [queues.md](queues.md)
