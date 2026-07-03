# Ozon Отзывы

## Права доступа

- Permission: `subscriber oz feedbacks`
- Middleware: `auth:api`, `verified`, `role:Подписчик`

## Назначение

Работа с отзывами Ozon: получение неотвеченных отзывов, отправка ответов, управление кабинетами, AI-автоответчик.

## Ключевые файлы

- `app/Http/Controllers/Api/Subscriber/Ozon/Feedbacks/FeedbacksController.php`
- `app/Http/Controllers/Api/Subscriber/Ozon/Feedbacks/FeedbacksClientsController.php`
- `app/Http/Traits/OzonApiTrait.php`
- `app/Models/Subscribers/Oz/Feedbacks/FeedbacksClients.php` — таблица `oz_feedbacks_clients`

## API эндпоинты

### Отзывы

- `POST /subscriber/oz/feedbacks/list` — список (`cabinet_id`, `last_id` для пагинации)
- `POST /subscriber/oz/feedbacks/send` — ответ на отзыв
- `POST /subscriber/oz/feedbacks/count` — количество неотвеченных

### Кабинеты

Resource `/subscriber/oz/feedbacks/cabinets`:

- `GET` — список
- `POST` — создание (`name`, `client_id`, `apikey`)
- `GET/PUT/DELETE /{id}` — CRUD

Дополнительно:

- `GET /cabinets/ai/data/{cabinet_id}`, `POST /cabinets/ai/data` — настройки AI
- `GET/POST /cabinets/bot-status` — статус бота

## Технические детали

- Ozon Review API через `OzonApiTrait`
- Пагинация: `last_id` + `has_next` из ответа Ozon
- Фильтр `empty_answer`: если `false`, отзывы без текста не показываются
- Для списка подгружается информация о товарах по SKU (`getProductInfo`)
- AI-ответы: `AiTaskType::OZON_FEEDBACK_ANSWER_AI`

## Связанные документы

- [ai-marketplace.md](ai-marketplace.md) — AI-инфраструктура
- [wb-feedbacks.md](wb-feedbacks.md) — аналог для WB (более развитая admin-часть)