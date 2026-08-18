# WB Отзывы

## Права доступа

- Permission: `subscriber wb feedbacks`
- Middleware панели: `auth`, `verified`, `panel.access`, `wb.cabinets.migrated`, `permission:subscriber wb feedbacks`
- Admin: `role:Супер-Админ|super-admin`

## Назначение

Работа с отзывами Wildberries: просмотр неотвеченных, ручная и автоматическая отправка ответов, шаблоны, статистика, AI-автоответчик по рейтингам.

Кабинет WB — **единый** ([wb-cabinets.md](wb-cabinets.md)): API-ключ и имя живут в `wb_cabinets`, настройки отзывов — в `wb_feedbacks_settings`. В UI нет отдельного списка «кабинетов отзывов»; используется активный кабинет из шапки.

## Ключевые файлы

### Web (Inertia)

- `app/Http/Controllers/Web/Subscriber/Wb/Feedbacks/FeedbacksController.php`
- `app/Http/Controllers/Web/Subscriber/Wb/Feedbacks/TemplatesController.php`
- `app/Http/Controllers/Web/Subscriber/Wb/Feedbacks/StatsController.php`
- `app/Http/Controllers/Web/Subscriber/Wb/Feedbacks/ClientsController.php` — legacy/compat helpers при необходимости
- Trait: `ResolvesSelectedWbCabinet`

### Services

- `app/Services/Subscriber/Wb/WbFeedbacksService.php`
- `app/Services/Subscriber/Wb/WbFeedbacksClientsService.php` — AI/bot settings через unified cabinet
- `app/Services/Subscriber/Wb/WbFeedbacksStatsService.php`
- `app/Services/Subscriber/Wb/WbFeedbacksTemplatesService.php`
- `app/Http/Traits/WBFeedbacksTrait.php`

### Runtime (cron / jobs)

- `app/Support/Wb/FeedbacksRuntimeCabinetResolver.php` — dual-source: `wb_cabinets` + legacy clients
- `app/Support/Wb/FeedbacksRuntimeClient.php`
- `app/Support/Wb/FeedbacksLegacySettingsProxy.php`
- `app/Console/Commands/SubscriberWbFeedbacksAnswer.php`

### Admin

- `app/Http/Controllers/Web/Admin/Feedbacks/CabinetController.php`
- `app/Services/Admin/AdminFeedbacksService.php`

### Модели

- `app/Models/Subscribers/Wb/WbCabinet.php` — единый кабинет
- `app/Models/Subscribers/Wb/Feedbacks/WbFeedbacksSettings.php` — `wb_feedbacks_settings` (1:1 к кабинету)
- `app/Models/Subscribers/Wb/Feedbacks/FeedbacksClients.php` — legacy `subs_wb_feedbacks_clients` (миграция)
- `app/Models/Subscribers/Wb/Feedbacks/Review.php`
- `app/Models/Subscribers/Wb/Feedbacks/ReviewStatistic.php`

## Web routes (Inertia)

Prefix: `/panel/wb/feedbacks` · name: `subscriber.wb.feedbacks.*`

| Method | URL | Named route | Inertia / ответ |
|--------|-----|-------------|-----------------|
| GET | `/` | `index` | `Subscriber/Wb/Feedbacks/Client/Show` (workspace) |
| GET | `/answered` | `answered` | JSON / widget data |
| POST | `/feedbacks` | `feedbacks.refresh` | Обновить список с WB |
| POST | `/feedbacks/send` | `feedbacks.send` | Отправить ответ |
| POST | `/ai` | `ai.update` | Настройки AI |
| POST | `/ai/generate` | `ai.generate` | Сгенерировать ответ |
| GET | `/templates` | `templates.index` | `…/Templates/Index` |
| POST | `/templates` | `templates.store` | |
| PUT | `/templates/{template}` | `templates.update` | |
| DELETE | `/templates/{template}` | `templates.destroy` | |
| POST | `/bot-status` | `bot-status.update` | Вкл/выкл шаблонный бот |
| GET | `/products/{product}` | `products.stats` | `…/Product/Stats` |

Редиректы legacy:

- `/clients/{client}` → `/panel/wb/feedbacks`
- `/clients/{client}/templates` → `/panel/wb/feedbacks/templates`
- `/clients/{client}/products/{product}` → `/panel/wb/feedbacks/products/{product}`

При отсутствии выбранного кабинета — `Subscriber/Wb/Shared/NoCabinet`.

## Admin (web)

- `/cw-page/services/feedbacks/*` — список кабинетов, статистика, AI-ответы (через Admin services)

## Лимиты и тарификация

- Число кабинетов WB ограничивается `limits_plan.wb_cabinets` (не `feedbacks_clients` для новых созданий) — см. [wb-cabinets.md](wb-cabinets.md)
- ИИ-ответы (ручные и авто) списывают кредиты. Стоимость — услуга `feedback_answer` из каталога `/cw-page/credit-pricing`, не хардкод. См. [credits-billing.md](credits-billing.md)
- Шаблонный автоответчик кредиты не тратит
- Логи провайдера: `AiTaskType::WB_FEEDBACK_ANSWER_AI` (см. [ai-marketplace.md](ai-marketplace.md))

### Списание кредитов за ИИ-ответ

- Ручная генерация: `POST /panel/wb/feedbacks/ai/generate` (`FeedbacksController::generateAi`). Перед GPT проверяется баланс; успех — одно `spend`. Повторная генерация — новая операция.
- Та же точка для кнопки «Сгенерировать ИИ» на странице шаблонов.
- Авто: команда `subscriber:wb-feedbacks-answer`. Списание только после успешной отправки ответа в WB. Ключ: `feedback_answer:auto:{cabinetId}:{reviewId}`.
- Стоимость берётся из каталога кредитов (`feedback_answer`).
- Frontend получает `creditsCost` с backend quote; сумму с клиента не принимают.
- История: `credit_ledger`, подпись «Ответ на отзыв Wildberries».

## Технические детали

- WB Feedbacks API через `WBFeedbacksTrait` (`GET /api/v1/feedbacks`)
- Неотвеченные отзывы (страница workspace):
  - count: `GET /api/v1/feedbacks/count-unanswered` (за всё время)
  - list: `GET /api/v1/feedbacks?isAnswered=false&take&skip` — **постраничная догрузка** (page size 1000, cap 25000), без dateFrom/dateTo
  - общая логика: `WBFeedbacksTrait::fetchAllUnansweredFeedbacks` — UI (`WbFeedbacksService`) и команда `subscriber:wb-feedbacks-answer` (AI + шаблоны)
  - автоответчик: макс. **150 успешных ответов на кабинет за один проход** (AI и шаблоны отдельно); остальное — на следующих запусках cron
  - `nmId` передаётся в API WB как точный артикул товара (UI-фильтр)
  - фильтр по оценке (1–5) — на бэкенде после ответа WB (в API WB нет multi-rating); в боте — `ai_ratings` / шаблоны
  - UI-пагинация и фильтры — query-параметры Inertia (`nmId`, `ratings[]`, `page`, `per_page`)
- Фильтрация по брендам: поле `brands` в `wb_feedbacks_settings` (через запятую). Ответ WB пост-фильтруется по `productDetails.brandName` (case-insensitive) **после** полной догрузки. Если бренды не заданы — показываются все.
- Бот-статус и AI-рейтинги хранятся в `wb_feedbacks_settings` (не в legacy client)
- Статистика агрегируется в `ReviewStatistic` / `ReviewCategoryStatistic` / `ReviewProductStatistic` с `cabinet_id` = `wb_cabinets.id` (после миграции)
- Reviews / templates: `cabinet_id` / `client_id` указывают на unified cabinet id
- Cron dual-source: пока у кого-то `is_migrated = false`, `FeedbacksRuntimeCabinetResolver` подмешивает legacy clients

## Связанные документы

- [wb-cabinets.md](wb-cabinets.md) — единый кабинет
- [credits-billing.md](credits-billing.md) — списание кредитов за ИИ-ответы
- [ai-marketplace.md](ai-marketplace.md) — логи провайдера
