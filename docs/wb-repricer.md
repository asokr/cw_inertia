# WB Репрайсер

## Права доступа

- Permission: `subscriber wb repricer`
- Middleware: `auth:api`, `verified`, `role:Подписчик`
- Admin: `role:Супер-Админ|super-admin`

## Назначение

Автоматическое управление ценами на Wildberries по трём стратегиям: по остаткам на складах, по расписанию (время) и по ценам конкурентов. Интегрируется с [wb-promo-calculator.md](wb-promo-calculator.md) для массового добавления номенклатур.

## Ключевые файлы

### Controllers

- `app/Http/Controllers/Api/Subscriber/Wb/RePricer/RepricerCabinetsController.php`
- `app/Http/Controllers/Api/Subscriber/Wb/RePricer/RepricerStocksController.php`
- `app/Http/Controllers/Api/Subscriber/Wb/RePricer/RepricerSettingsController.php`
- `app/Http/Controllers/Api/Subscriber/Wb/RePricer/RepricerCompetitorsController.php`
- `app/Http/Controllers/Api/Admin/services/repricer/AdminRepricerController.php`

### Services & Jobs

- `app/Services/Wb/WbSearchService.php` — поиск конкурентов через Node-сервис
- `app/Jobs/ApplyRepricerStrategyOneJob.php` — применение стратегии по расписанию
- `app/Jobs/ProcessRepricerCompetitorJob.php` — обработка цен конкурентов
- `app/Jobs/UpdateRepricerStocksJob.php` — обновление по остаткам

### Модели

- `app/Models/Subscribers/Wb/Repricer/RepricerCabinets.php`
- `app/Models/Subscribers/Wb/Repricer/RepricerStocks.php`
- `app/Models/Subscribers/Wb/Repricer/RepricerSettings.php`
- `app/Models/Subscribers/Wb/Repricer/RepricerCompetitor.php`
- `app/Models/WbSearchRequest.php`

## Web routes (Inertia, Phase 3b.6 v1)

Permission: `subscriber wb repricer` · Prefix: `/panel/wb/repricer`

| Web route | Inertia Page |
| --- | --- |
| `GET /` | `Subscriber/Wb/Repricer/Index` |
| `GET /cabinets/{cabinet}` | `Subscriber/Wb/Repricer/Cabinet/Show` |
| `GET /cabinets/{cabinet}/time` | `Subscriber/Wb/Repricer/Cabinet/Time/Index` |
| `GET /cabinets/{cabinet}/stocks` | `Subscriber/Wb/Repricer/Cabinet/Stocks/Index` |

Стратегия **по конкурентам** и mass-страницы (`time/mass`, `stocks/mass`) в v1 не мигрированы — остаются в backlog.

## API эндпоинты (Subscriber)

### Кабинеты

- Resource `/subscriber/wb/repricer/cabinets`
- `POST /subscriber/wb/repricer/cabinets/logs` — логи изменений цен

### Стратегия 1: по остаткам (stocks)

- Resource `/subscriber/wb/repricer/stocks` (кроме index)
- `POST /stocks/mass/` — загрузка данных из WB
- `PUT /stocks/mass/` — массовое обновление
- `DELETE /stocks/mass/` — массовое удаление
- `POST /stocks/sizes/` — размеры из WB
- `POST /stocks/{stock}/reset` — сброс

### Стратегия 2: по времени (settings)

- Resource `/subscriber/wb/repricer` (кроме index)
- `POST /mass/` — загрузка из WB
- `PUT /mass/` — массовое обновление
- `DELETE /mass/` — массовое удаление

### Стратегия 3: по конкурентам (competitors)

- `GET /competitors/search` — запуск поиска
- `GET /competitors/search/status` — статус поиска
- `POST /competitors/info` — bulk-информация о конкурентах
- `PATCH /competitors/{competitor}/status` — вкл/выкл
- `POST /competitors/nm-data` — данные номенклатуры
- Resource `/subscriber/wb/repricer/competitors`

### Webhook (Node-сервис)

- `POST /services/wb-search/webhook` — callback результатов поиска конкурентов (без auth подписчика)

## Admin API

- `POST /admin/services/repricer/cabinets` — список кабинетов
- `POST /admin/services/repricer/nmids` — список номенклатур
- `POST /admin/services/repricer/logs` — логи

## Фоновые процессы

| Job | Назначение |
|-----|------------|
| `ApplyRepricerStrategyOneJob` | Периодическое применение цен по расписанию |
| `ProcessRepricerCompetitorJob` | Пересчёт цен по конкурентам |
| `UpdateRepricerStocksJob` | Обновление цен при изменении остатков |

## Технические детали

- Поиск конкурентов делегируется внешнему Node-сервису (`WbSearchService`), результат приходит через webhook
- `RepricerCompetitor` хранит `nm_id`, список конкурентов, `difference` (percent/amount), `competitors_price_type` (min/average/max)
- Кабинеты привязаны к `user_id` и WB API-ключу
- Логи изменений цен доступны подписчику и в админке