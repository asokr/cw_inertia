# Единый кабинет Wildberries

## Назначение

Все инструменты WB работают от **одного общего кабинета** продавца, а не от отдельных «кабинетов» внутри каждого сервиса.

Раньше у каждого инструмента (отзывы, ценообразование, репрайсер, рентабельность, ИИ-анализ) был свой CRUD кабинетов со своим API-ключом. Теперь:

1. Пользователь создаёт **кабинет WB** один раз: название + персональный API-ключ.
2. В шапке панели выбирает **активный кабинет** (`users.selected_wb_cabinet_id`).
3. Все WB-инструменты читают данные и настройки для этого кабинета.
4. Данные инструментов (отзывы, цены, настройки репрайсера и т.д.) ссылаются на `wb_cabinets.id` через `cabinet_id`.

Ozon-инструменты по-прежнему имеют **свои** кабинеты на инструмент — унификация касается только Wildberries.

## UX

| Элемент | Поведение |
|---------|-----------|
| Переключатель в шапке | `resources/js/components/subscriber/wb/WbCabinetSwitcher.vue` — список, выбор, создание, редактирование, удаление |
| Нет кабинета | Инструменты показывают `Subscriber/Wb/Shared/NoCabinet` |
| Старые URL с `/cabinets/{id}` | Редирект на flat workspace (без id в path) |
| Миграция со старых кабинетов | Wizard `/panel/wb/cabinets/migration` — обязателен, пока есть немигрированные legacy-кабинеты |

Shared props Inertia (только для `/panel/*`):

- `wb_cabinets` — `[{ id, name }, ...]`
- `selected_wb_cabinet` — `{ id, name } | null`
- `wb_api_key_warning` — текст предупреждения о полном API-ключе
- `wb_migration_required` — `bool`

## Права и middleware

Маршруты панели:

```
auth + verified + panel.access + wb.cabinets.migrated
```

- Permission на **конкретный инструмент** остаётся прежним (`subscriber wb feedbacks`, `subscriber wb repricer`, …).
- Управление кабинетами (`/panel/wb/cabinets/*`) доступно любому подписчику с доступом в панель (отдельного permission нет).
- Middleware `wb.cabinets.migrated` (`EnsureWbCabinetsMigrated`): если у пользователя есть legacy-кабинеты с `is_migrated = false`, все запросы в `/panel/*` (кроме wizard миграции) редиректят на миграцию; JSON → `423` + `migration_required: true`.

## Web routes

Prefix: `/panel/wb/cabinets` · name: `subscriber.wb.cabinets.*`

| Method | URL | Named route | Назначение |
|--------|-----|-------------|------------|
| GET | `/migration` | `…migration` | Wizard миграции |
| POST | `/migration/cabinets` | `…migration.cabinets.store` | Создать новый кабинет из wizard |
| POST | `/migration/run` | `…migration.run` | Применить привязки/удаления |
| GET | `/` | `…index` | Redirect → `/panel` (отдельной index-страницы нет) |
| POST | `/` | `…store` | Создать кабинет |
| PUT | `/{cabinet}` | `…update` | Обновить имя/ключ |
| DELETE | `/{cabinet}` | `…destroy` | Удалить кабинет |
| POST | `/select` | `…select` | Выбрать активный (`cabinet_id`) |

## Ключевые файлы

### Модель и валидация ключа

- `app/Models/Subscribers/Wb/WbCabinet.php` — таблица `wb_cabinets`
- `app/Models/Subscribers/Wb/Feedbacks/WbFeedbacksSettings.php` — настройки отзывов (`wb_feedbacks_settings`)
- `app/Services/Wb/WbCabinetApiKeyValidator.php` — ping `common-api.wildberries.ru/ping` + probe permissions
- `app/Services/Subscriber/Wb/WbCabinetService.php` — CRUD, select, лимиты
- `app/Services/Subscriber/Wb/WbCabinetMigrationService.php` — wizard + rewrite child rows
- `app/Support/Wb/WbCabinetServiceRegistry.php` — реестр legacy-сервисов для миграции
- `app/Support/Wb/SelectedWbCabinet.php` — resolve активного кабинета

### Web / middleware / UI

- `app/Http/Controllers/Web/Subscriber/Wb/Cabinets/CabinetsController.php`
- `app/Http/Controllers/Web/Subscriber/Wb/Cabinets/MigrationController.php`
- `app/Http/Controllers/Web/Subscriber/Concerns/ResolvesSelectedWbCabinet.php`
- `app/Http/Controllers/Web/Subscriber/Concerns/EnsuresWbCabinetOwnership.php`
- `app/Http/Middleware/EnsureWbCabinetsMigrated.php`
- `app/Http/Middleware/HandleInertiaRequests.php` — shared props
- `resources/js/components/subscriber/wb/WbCabinetSwitcher.vue`
- `resources/js/Pages/Subscriber/Wb/Cabinets/Migration.vue`
- `resources/js/Pages/Subscriber/Wb/Shared/NoCabinet.vue`

### Миграции БД

- `2026_07_24_120000_create_wb_cabinets_table`
- `2026_07_24_120100_create_wb_feedbacks_settings_table`
- `2026_07_24_120200_add_migration_columns_to_legacy_wb_cabinets`
- `2026_07_24_120300_add_selected_wb_cabinet_id_to_users_table`
- `2026_07_24_120400_drop_wb_price_calc_v3_fk_to_legacy_cabinets`
- `2026_07_24_180000_drop_legacy_wb_cabinet_foreign_keys`

## Схема данных

### `wb_cabinets`

| Поле | Описание |
|------|----------|
| `id` | PK — этот id используют все tool-таблицы как `cabinet_id` |
| `user_id` | Владелец |
| `name` | Отображаемое имя |
| `apikey` | Зашифрованный API-ключ (`EncryptCast`) |
| `api_key_hash` | `sha256` ключа, unique (дедупликация) |
| `error_code` / `error_message` | Ошибки API (в т.ч. для репрайсера: 401/403/429) |

Константы на модели:

- `FATAL_ERROR_CODES = [401, 403]`
- `SKIP_DISPATCH_ERROR_CODES = [401, 403, 429]`
- `RATE_LIMIT_DISABLE_THRESHOLD = 8`

### `users.selected_wb_cabinet_id`

Активный кабинет пользователя. При удалении выбранного — переключается на другой (или `null`).

### `wb_feedbacks_settings`

1:1 к `wb_cabinets` (cascade on delete). Поля, которые раньше жили в `FeedbacksClients`:

- `brands`, `bot_status`, `ai_status`, `ai_ratings`, `review_type`

### Tool-данные

После миграции (и для новых пользователей) `cabinet_id` в tool-таблицах = `wb_cabinets.id`:

| Сервис | Примеры таблиц |
|--------|----------------|
| Отзывы | reviews, review statistics, templates (`client_id`), settings |
| Ценообразование | `wb_price_calc_v3_data`, settings V2/V3, legacy data |
| Репрайсер | settings, stocks, logs, competitors |
| Рентабельность | reports (+ items через report) |
| ИИ-анализ | reports (+ ai analyses через report) |

Legacy-таблицы кабинетов (`subs_wb_feedbacks_clients`, `wb_price_cabinets`, `wb_repricer_cabinets`, `wb_profitability_cabinets`, `wb_ai_cabinet_analyzer_cabinets`) сохранены для миграции и помечаются:

- `is_migrated`, `migrated_at`, `wb_cabinet_id`

После привязки строки помечаются мигрированными; child rows переписывают `cabinet_id` / `client_id` на id нового `wb_cabinets`.

## Как инструменты резолвят кабинет

Trait `ResolvesSelectedWbCabinet`:

```php
$cabinet = $this->requireSelectedWbCabinet($request, 'Название инструмента');
// WbCabinet | Inertia NoCabinet page

$cabinet = $this->requireSelectedWbCabinetJson($request);
// WbCabinet | JsonResponse 422
```

Jobs и background-команды берут `WbCabinet::find($cabinetId)` (id уже из `wb_cabinets`).

Для **cron отзывов** (пока идут миграции пользователей) используется dual-source:

- `FeedbacksRuntimeCabinetResolver` — unified (`WbCabinet` + settings) **и** legacy (`FeedbacksClients` с `is_migrated = false`)
- `FeedbacksRuntimeClient` — единый DTO для бота/AI

## Миграция пользователя (wizard)

1. Middleware обнаруживает unmigrated legacy-кабинеты → redirect на `/panel/wb/cabinets/migration`.
2. Пользователь создаёт один или несколько новых `wb_cabinets` (если ещё нет).
3. Для каждого старого кабинета сервиса:
   - **привязать** к одному общему кабинету (не более одного кабинета сервиса на один `wb_cabinet`), **или**
   - **удалить** (с каскадом tool-данных).
4. `WbCabinetMigrationService::migrate()`:
   - rewrite children (`cabinet_id` → new id);
   - для feedbacks — перенос настроек в `wb_feedbacks_settings`;
   - для repricer — перенос `error_code` / `error_message` на `WbCabinet`;
   - mark legacy `is_migrated = true`, `wb_cabinet_id = …`;
   - при необходимости выставить `selected_wb_cabinet_id`.
5. После полной миграции middleware больше не блокирует панель.

Сервисы в registry:

| key | label | legacy model |
|-----|-------|--------------|
| `feedbacks` | Управление отзывами | `FeedbacksClients` |
| `price_calc` | Ценообразование | `PriceCalculationCabinets` |
| `repricer` | Репрайсер | `RepricerCabinets` |
| `profitability` | Рентабельность | `ProfitabilityCabinet` |
| `ai_cabinet_analyzer` | ИИ анализ кабинета | `AiCabinetAnalyzerCabinet` |

## Лимиты

- План-лимит: `limits_plan.wb_cabinets` (лейбл: «Кабинеты Wildberries»).
- Fallback для старых планов: `max(feedbacks_clients, price_calc_clients)`, если `wb_cabinets` ещё нет в JSON.
- При создании кабинета лимит списывается через `ToolLimits` (если ключ есть в плане).
- Уникальность API-ключа глобальная по `api_key_hash`.

## Требования к API-ключу

Для корректной работы **всех** инструментов нужен **персональный** API-ключ WB с полным набором разрешений. Тестовый или урезанный ключ может ломать отдельные сервисы — об этом предупреждает `WbCabinetService::API_KEY_WARNING` (shared prop + UI).

Валидация при create/update:

1. `GET https://common-api.wildberries.ru/ping`
2. Опциональный probe прав (permission warnings, кабинет всё равно создаётся)

## Паттерн flat workspace

Инструменты WB больше не имеют списка кабинетов на своей главной. Типичный URL:

```
/panel/wb/feedbacks
/panel/wb/price-calc
/panel/wb/repricer
/panel/wb/profitability
/panel/wb/ai-cabinet-analyzer
```

Legacy path `/panel/wb/{tool}/cabinets/{cabinet}` → redirect на flat URL.

Контроллеры: Web-слой → Service; `cabinet_id` берётся из selected cabinet, а не из path.

## Связанные документы

- [wb-feedbacks.md](wb-feedbacks.md)
- [wb-price-calculation-v3.md](wb-price-calculation-v3.md)
- [wb-repricer.md](wb-repricer.md)
- [wb-profitability.md](wb-profitability.md)
- [wb-ai-cabinet-analyzer.md](wb-ai-cabinet-analyzer.md)
- [wb-promo-calculator.md](wb-promo-calculator.md)
