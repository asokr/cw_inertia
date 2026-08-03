# WB Репрайсер

## Права доступа

- Permission: `subscriber wb repricer`
- Middleware панели: `auth`, `verified`, `panel.access`, `wb.cabinets.migrated`, `permission:subscriber wb repricer`
- Admin: `role:Супер-Админ|super-admin`

## Назначение

Автоматическое управление ценами на Wildberries по стратегиям: по остаткам на складах и по расписанию (время). Интегрируется с [wb-promo-calculator.md](wb-promo-calculator.md) для массового добавления номенклатур.

Кабинет WB — **единый** ([wb-cabinets.md](wb-cabinets.md)): API-ключ, ошибки API (`error_code` / `error_message`) живут в `wb_cabinets`. Стратегии (settings, stocks, competitors, logs) ссылаются на `cabinet_id` = `wb_cabinets.id`.

В UI нет отдельного списка кабинетов репрайсера: hub и стратегии работают для **активного** кабинета из шапки.

## Ключевые файлы

### Web (Inertia)

- `app/Http/Controllers/Web/Subscriber/Wb/Repricer/StrategyHubController.php`
- `app/Http/Controllers/Web/Subscriber/Wb/Repricer/CabinetsController.php` — логи
- `app/Http/Controllers/Web/Subscriber/Wb/Repricer/TimeSettingsController.php`
- `app/Http/Controllers/Web/Subscriber/Wb/Repricer/StocksController.php`
- Trait: `ResolvesSelectedWbCabinet`

### Services & Jobs

- `app/Services/Subscriber/Wb/RepricerCabinetsService.php`
- `app/Services/Subscriber/Wb/RepricerStocksService.php`
- `app/Services/Subscriber/Wb/RepricerTimeSettingsService.php`
- `app/Services/Wb/WbSearchService.php` — поиск конкурентов через Node-сервис
- `app/Jobs/ApplyRepricerStrategyOneJob.php` — применение стратегии по расписанию
- `app/Jobs/ProcessRepricerCompetitorJob.php` — обработка цен конкурентов
- `app/Jobs/UpdateRepricerStocksJob.php` — обновление по остаткам (берёт `WbCabinet`)

### Модели

- `app/Models/Subscribers/Wb/WbCabinet.php` — единый кабинет + API errors
- `app/Models/Subscribers/Wb/Repricer/RepricerCabinets.php` — legacy `wb_repricer_cabinets` (миграция)
- `app/Models/Subscribers/Wb/Repricer/RepricerStocks.php`
- `app/Models/Subscribers/Wb/Repricer/RepricerSettings.php`
- `app/Models/Subscribers/Wb/Repricer/RepricerCompetitor.php`
- `app/Models/Subscribers/Wb/Repricer/RepricerLogs.php`
- `app/Models/WbSearchRequest.php`

### Admin

- `app/Services/Admin/AdminRepricerService.php`

## Web routes (Inertia)

Prefix: `/panel/wb/repricer` · name: `subscriber.wb.repricer.*`

| Method | URL | Named route | Inertia / ответ |
|--------|-----|-------------|-----------------|
| GET | `/` | `index` | `Subscriber/Wb/Repricer/Cabinet/Show` (hub стратегий) |
| POST | `/logs` | `logs` | Логи изменений цен |
| GET | `/time` | `time.index` | `…/Cabinet/Time/Index` |
| POST | `/time` | `time.store` | |
| PUT | `/time/{setting}` | `time.update` | |
| DELETE | `/time/{setting}` | `time.destroy` | |
| GET | `/stocks` | `stocks.index` | `…/Cabinet/Stocks/Index` |
| POST | `/stocks` | `stocks.store` | |
| PUT | `/stocks/{stock}` | `stocks.update` | |
| DELETE | `/stocks/{stock}` | `stocks.destroy` | |
| POST | `/stocks/sizes` | `stocks.sizes` | Размеры из WB |
| POST | `/stocks/{stock}/reset` | `stocks.reset` | |

Редиректы legacy:

- `/cabinets/{cabinet}` → `/panel/wb/repricer`
- `/cabinets/{cabinet}/time` → `/panel/wb/repricer/time`
- `/cabinets/{cabinet}/stocks` → `/panel/wb/repricer/stocks`

Стратегия **по конкурентам** и mass-страницы (`time/mass`, `stocks/mass`) в UI v1 не вынесены на отдельный full-flow (логика/jobs могут оставаться в backend).

При отсутствии выбранного кабинета — `Subscriber/Wb/Shared/NoCabinet`.

## Admin (web)

- `/cw-page/services/repricer/*` — кабинеты, номенклатуры, логи

## Фоновые процессы

| Job | Назначение |
|-----|------------|
| `ApplyRepricerStrategyOneJob` | Периодическое применение цен по расписанию |
| `ProcessRepricerCompetitorJob` | Пересчёт цен по конкурентам |
| `UpdateRepricerStocksJob` | Обновление цен при изменении остатков |

Jobs работают с `WbCabinet` (не legacy `RepricerCabinets`). Ошибки 401/403/429 пишутся в `wb_cabinets.error_*`; при фатальных — уведомление `WbCabinetAuthorizationNotification`.

## Технические детали

- Поиск конкурентов делегируется внешнему Node-сервису (`WbSearchService`), результат приходит через webhook `POST /api/services/wb-search/webhook`
- `RepricerCompetitor` хранит `nm_id`, список конкурентов, `difference` (percent/amount), `competitors_price_type` (min/average/max)
- `cabinet_id` в settings/stocks/logs/competitors = `wb_cabinets.id`
- Константы skip/fatal на `WbCabinet` (и зеркально на legacy model для миграции)
- Логи изменений цен доступны подписчику и в админке

### Периоды TIME-стратегии (`terms`)

Массив объектов `{ start, end, value }`. Два формата:

| Формат | Пример | Поведение бота |
|--------|--------|----------------|
| Ежедневно | `"09:00"` / `"18:00"` | каждый день в этом окне |
| Разовый | `"2026-08-01 10:00:00"` / `"2026-08-07 22:00:00"` | только в указанном интервале дат (МСК) |

Хелпер: `App\Support\Wb\RepricerTimePeriod`. UI: «+ Ежедневно» / «+ С датой». Из [промокалькулятора](wb-promo-calculator.md) приходит разовый период с датами.

## Связанные документы

- [wb-cabinets.md](wb-cabinets.md)
- [wb-promo-calculator.md](wb-promo-calculator.md)
