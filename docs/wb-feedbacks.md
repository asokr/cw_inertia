# WB Отзывы

## Права доступа

- Permission: `subscriber wb feedbacks`
- Middleware: `auth:api`, `verified`, `role:Подписчик`
- Admin: `role:Супер-Админ|super-admin`

## Назначение

Работа с отзывами Wildberries: просмотр неотвеченных, ручная и автоматическая отправка ответов, шаблоны, статистика, AI-автоответчик по рейтингам.

## Ключевые файлы

### Subscriber

- `app/Http/Controllers/Api/Subscriber/Wb/Feedbacks/FeedbacksController.php`
- `app/Http/Controllers/Api/Subscriber/Wb/Feedbacks/FeedbacksClientsController.php`
- `app/Http/Controllers/Api/Subscriber/Wb/Feedbacks/FeedbacksTemplatesController.php`
- `app/Http/Controllers/Api/Subscriber/Wb/Feedbacks/FeedbacksStatController.php`
- `app/Http/Traits/WBFeedbacksTrait.php`

### Admin

- `app/Http/Controllers/Api/Admin/services/feedbacks/AdminFeedbacksController.php`

### Модели

- `app/Models/Subscribers/Wb/Feedbacks/FeedbacksClients.php` — таблица `subs_wb_feedbacks_clients`
- `app/Models/Subscribers/Wb/Feedbacks/Review.php`
- `app/Models/Subscribers/Wb/Feedbacks/ReviewStatistic.php`

## API эндпоинты (Subscriber)

Префикс: `/subscriber/wb/feedbacks`

### Отзывы

- `POST /list` — список неотвеченных (`client_id`, `skip`)
- `POST /send` — отправка ответа в WB

### Кабинеты (clients)

- `GET /client` — список кабинетов
- `POST /client` — создание (проверка API-ключа, лимит `feedbacks_clients` в подписке)
- `GET/PUT/DELETE /client/{id}` — CRUD
- `GET /client/bot-status`, `POST /client/bot-status` — статус автоответчика
- `GET /client/ai/data`, `POST /client/ai/data` — настройки AI-ответов

### Шаблоны

- `POST /templates/all` — все шаблоны
- Resource `/templates` (кроме index)

### Статистика

- `GET /widget/stats`, `GET /widget/answered`
- `GET /stats/product` — статистика по товару

## Admin API

- `GET /admin/services/feedbacks/cabinets` — список кабинетов с подписчиками
- `GET /admin/services/feedbacks/cabinets/{id}/stats` — статистика (`stat_type`: weekly, monthly, half_year, yearly)
- `GET /admin/services/feedbacks/cabinets/{id}/answered` — отвеченные отзывы
- `POST /admin/services/feedbacks/cabinets/{id}/recalculate` — пересчёт статистики
- `GET /admin/services/feedbacks/ai-answers` — логи AI-ответов

## Лимиты и тарификация

- При создании кабинета списывается `limits_plan.feedbacks_clients` из активной подписки
- AI-ответы: `AiTaskType::WB_FEEDBACK_ANSWER_AI` (см. [ai-marketplace.md](ai-marketplace.md))

## Технические детали

- WB Feedbacks API через `WBFeedbacksTrait` (`GET /api/v1/feedbacks`)
- Неотвеченные отзывы (страница кабинета):
  - count: `GET /api/v1/feedbacks/count-unanswered` (за всё время)
  - list: `GET /api/v1/feedbacks?isAnswered=false&take&skip` — **постраничная догрузка** (page size 1000, cap 25000), без dateFrom/dateTo
  - общая логика: `WBFeedbacksTrait::fetchAllUnansweredFeedbacks` — UI (`WbFeedbacksService`) и команда `subscriber:wb-feedbacks-answer` (AI + шаблоны)
  - автоответчик: макс. **150 успешных ответов на кабинет за один проход** (AI и шаблоны отдельно); остальное — на следующих запусках cron
  - `nmId` передаётся в API WB как точный артикул товара (UI-фильтр)
  - фильтр по оценке (1–5) — на бэкенде после ответа WB (в API WB нет multi-rating); в боте — `ai_ratings` / шаблоны
  - UI-пагинация и фильтры — query-параметры Inertia (`nmId`, `ratings[]`, `page`, `per_page`)
- Фильтрация по брендам: поле `brands` в кабинете (через запятую при создании/редактировании). Ответ WB пост-фильтруется по `productDetails.brandName` (case-insensitive) **после** полной догрузки. Если бренды не заданы — показываются все.
- Бот-статус и AI-рейтинги хранятся в `FeedbacksClients`
- Статистика агрегируется в `ReviewStatistic` / `ReviewCategoryStatistic`
- Обработанные отзывы: `GET /panel/wb/feedbacks/clients/{client}/answered` (limit/offset/has_text/has_photo)