# AI Marketplace (WB/Ozon)

## Права доступа

- Permission: `subscriber ai`
- Middleware: `auth:api`, `verified`, `role:Подписчик`, `throttle:api`
- Admin: `role:Супер-Админ|super-admin`

## Назначение

AI Marketplace - единый backend-инструмент для AI-функций в кабинетах маркетплейсов.

Что покрывает инструмент:

- генерация и адаптация текстов карточек;
- генерация и редактирование изображений;
- генерация видео по тексту, изображению и набору референсов;
- контроль лимитов подписки и тарификации;
- централизованное логирование запросов/ответов провайдеров;
- аналитика расходов AI в админке.

## Где используется

- Subscriber API: рабочие сценарии пользователя (создание контента и медиа).
- Admin API: контроль логов, статусов, токенов, стоимости и архивов затрат.
- Планировщик и команды: агрегация расходов и очистка медиа/логов.

## Ключевые файлы

### Контроллеры Subscriber

- `app/Http/Controllers/Api/Subscriber/Ai/GeminiController.php`
- `app/Http/Controllers/Api/Subscriber/Ai/GrokVideoController.php`
- `app/Http/Controllers/Api/Subscriber/Ai/AiMediaController.php`

Примечание по маршрутам text/image:

- Для текстовых и image-задач используется единый контроллер `GeminiController`.
- Legacy endpoint `POST /subscriber/ai/image-gen` оставлен как алиас и направлен в `GeminiController@marketplace` для обратной совместимости старого фронта.
- Старые маршруты `subscriber/ai/ask`, `subscriber/ai/dialog`, `subscriber/ai/image` выведены из активной маршрутизации.

### Сервисы провайдеров

- `app/Services/Gemini/GeminiApiClient.php`
- `app/Services/Grok/GrokVideoApiClient.php`
- `app/Services/Grok/GrokImageApiClient.php`
- `app/Services/OpenAi/OpenAiTextFallbackClient.php`
- `app/Services/Ai/AiMediaStorageService.php`

### Логи и стоимость

- `app/Models/AiRequestLog.php`
- `app/Models/AiCost.php`
- `app/Console/Commands/AggregateAiCosts.php`

### Контроллеры Admin

- `app/Http/Controllers/Api/Admin/services/ai/AdminAiMarketplaceLogsController.php`
- `app/Http/Controllers/Api/Admin/services/ai/AdminAiCostsController.php`
- `app/Http/Controllers/Api/Admin/services/ai/AdminAiMediaController.php`

### Конфигурация

- `config/services.php` (ключи/настройки Gemini, Grok, media)
- `config/ai_pricing.php` (тарифы расчёта стоимости)
- `config/filesystems.php` (диск `private` для внутреннего AI media)

## Поддерживаемые сценарии

### Текстовые задачи (Gemini)

- `generate_description`
- `rewrite_text`
- `rewrite_ozon`
- `rewrite_wb`
- `adapt_wb`
- `adapt_ozon`
- `generate_ozon_rich`
- `rich_description` (алиас)

Особенности:

- единая валидация входных данных;
- проверка лимитов до вызова провайдера;
- списание лимитов после успешного результата;
- логирование токенов и текста ответа.
- при недоступности Gemini включается fallback на ChatGPT (`/v1/chat/completions`).

### Image-задачи (Gemini)

- `generate_image`
- `edit_image`

Особенности:

- поддержка нескольких входных референсов (`images[]`);
- ограничение входного изображения до 10MB;
- поддержка параметров `aspectRatio`, `resolution`;
- тарификация лимита `AI_IMAGE_QUERY` зависит от `resolution`:
    - `default = 1` лимит за 1 изображение;
    - `1K = 2` лимита;
    - `2K = 3` лимита;
    - `4K = 3` лимита.
- при недоступности Gemini включается fallback на Grok Image API:
    - без входных изображений: `POST /v1/images/generations`;
    - с входными изображениями: `POST /v1/images/edits`;
    - входные изображения передаются в base64 (`data URI`) и поддерживается несколько изображений.

### Видео-задачи (Grok)

- text-to-video;
- image-to-video;
- scene/reference-to-video (`reference/start`, 1..7 изображений).

Особенности:

- отдельный запуск и отдельный опрос статуса по `request_id`;
- лимит считается в секундах: `1 секунда = 1 лимит`;
- для `720p` применяется коэффициент x2, для `480p` x1;
- при `done` видео сохраняется во внутреннее private-хранилище и отдается через backend endpoint;
- для `generate_video_from_image` можно передавать URL/data URI/base64.

## Subscriber API

### 1) `POST /subscriber/ai/marketplace`

Единая точка входа для текста и изображений.

Назначение:

- принять `task_type` и входные параметры;
- провалидировать payload;
- проверить лимиты подписки;
- вызвать Gemini;
- сохранить лог запроса/ответа;
- вернуть результат и обновлённые лимиты.

Ключевые поля ответа:

- `success`, `messages`, `data`;
- результат задачи (`text` и/или `images`);
- для image-задач `images` содержит массив URL на внутренние media endpoints (не base64);
- актуальные лимиты (`AI_TEXT_QUERY`, `AI_IMAGE_QUERY` + extra/total).

### 2) `POST /subscriber/ai/video/start`

Старт видео-задачи (text-to-video/image-to-video).

Параметры (основные):

- `task_type`;
- `prompt`;
- `duration`;
- `resolution` (`480p|720p`);
- `aspect_ratio` (или alias `aspectRatio`);
- `image` (для image-to-video, до 10MB).

Возвращает:

- `request_id` внешнего провайдера;
- статус запуска;
- служебные данные для дальнейшего polling.

### 3) `POST /subscriber/ai/video/reference/start`

Старт scene/reference-to-video.

Дополнительная валидация:

- `images`: от 1 до 7;
- `duration`: до 10 секунд;
- допустимые `resolution` и `aspect_ratio`.

### 4) `GET /subscriber/ai/video/status/{request_id}`

Проверка состояния видео-задачи.

Обработка статусов:

- `pending`: задача ещё обрабатывается;
- `done`: видео готово, возвращается `video.url` (внутренний backend URL) и `provider_url`;
- `expired`: возвращается понятная ошибка;
- `filtered_by_moderation`: отдельное сообщение, что контент не прошёл модерацию.

### 5) `GET /subscriber/ai/media/{path}`

Выдача private AI media для владельца файла.

Особенности:

- доступ только для авторизованного пользователя;
- разрешены только пути под `image_prefix`/`video_prefix`;
- путь обязан содержать префикс `user-{auth_user_id}`;
- файл отдается stream-ответом с `Content-Type` и `Content-Length`.

## Admin API

### 1) `GET /api/admin/services/ai/marketplace-logs`

Список логов AI-запросов для админки.

Что можно анализировать:

- тип задачи, провайдер, модель;
- payload запроса к провайдеру (preview/full);
- ответ провайдера (preview/full);
- токены, статусы, коды ошибок;
- текстовые и медиарезультаты.

### 2) `GET /api/admin/ai/costs/today`

Сводка расходов AI за текущий день с разбивкой по провайдерам (`gpt`, `gemini`, `grok`).

### 3) `GET /api/admin/ai/costs/archive`

Архив расходов по дням за выбранный период (`date_from`, `date_to`).

### 4) `GET /api/admin/services/ai/media/{path}`

Выдача private AI media для админки (просмотр логов изображений/видео).

## Поток обработки запроса

### Для текста/изображения

1. Клиент вызывает `POST /subscriber/ai/marketplace`.
2. Контроллер валидирует payload и лимиты.
3. Вызывается `GeminiApiClient`.
4. При ошибке недоступности Gemini выполняется fallback:
    - текст → `OpenAiTextFallbackClient`;
    - изображения → `GrokImageApiClient`.
5. Результат нормализуется (text/images).
6. Списываются лимиты.
7. Лог пишется в `ai_request_logs`.
8. Возвращается ответ с обновлёнными лимитами.

### Для видео

1. Клиент вызывает `POST /subscriber/ai/video/start` или `POST /subscriber/ai/video/reference/start`.
2. Контроллер валидирует payload и лимиты.
3. Входные изображения при необходимости сохраняются через `AiMediaStorageService`.
4. `GrokVideoApiClient` запускает задачу у провайдера.
5. Клиент опрашивает `GET /subscriber/ai/video/status/{request_id}`.
6. При `done` видео сохраняется в private-хранилище, лимиты списываются один раз.
7. Пишется финальный лог в `ai_request_logs`.

## Лимиты и тарификация

### Лимиты подписки

- `AI_TEXT_QUERY` / `AI_TEXT_QUERY_EXTRA`;
- `AI_IMAGE_QUERY` / `AI_IMAGE_QUERY_EXTRA`;
- `AI_VIDEO_QUERY`.

Контроллеры всегда:

- проверяют доступный лимит до обращения к провайдеру;
- списывают лимит только после успешной обработки;
- возвращают пользователю актуальные остатки.

### Стоимость

- сырые события и usage хранятся в `ai_request_logs`;
- агрегаты по дням формируются в `ai_costs`;
- стоимость считается по `config/ai_pricing.php`.

## Логирование

### Таблица `ai_request_logs`

Хранит:

- входные данные задачи (sanitized);
- payload к провайдеру (preview/full);
- ответ провайдера (preview/full, включая ошибки);
- текстовые результаты и метаданные изображений/видео;
- токены (`input_tokens`, `output_tokens`, и др.);
- статус генерации для видео (`pending|done|failed`);
- привязку к пользователю/подписчику/типу задачи.

### Таблица `ai_costs`

Агрегированная витрина для админки:

- дата;
- провайдер;
- модель;
- тип задачи;
- суммарные объёмы и стоимость.

## Хранение медиа

- используется внутренний диск `private` (локальное хранилище);
- входные/выходные файлы хранятся в структуре `.../user-{id}/YYYY/...`;
- доступ к файлам только через backend endpoints (`/subscriber/ai/media/{path}`, `/admin/services/ai/media/{path}`);
- медиафайлы удаляются при удалении генерации пользователем через `AiMediaStorageService` (`deleteTaskMedia`, `deleteImageTaskMedia`).

## Ошибки и устойчивость

- для Gemini high-load/429 возвращается понятное сообщение о временной перегрузке;
- видео-статусы Grok интерпретируются в пользовательские сообщения;
- все внешние ошибки логируются с расширенным контекстом для диагностики;
- при частичных ошибках провайдера сохраняется максимально полезный диагностический payload.

## Эксплуатационные заметки

- для корректной работы должны быть настроены ключи и URL провайдеров в `config/services.php`;
- воркер очередей должен обслуживать задачи, связанные с AI и смежными сервисами;
- рекомендуется следить за ретеншеном логов и размером private-хранилища;
- для финансовой аналитики важно, чтобы команда агрегации расходов запускалась регулярно по расписанию.

## Быстрый чек-лист при проблемах

1. Проверить лимиты подписки пользователя.
2. Проверить валидность входного payload (особенно размер/формат image).
3. Проверить `ai_request_logs` по пользователю и `task_type`.
4. Проверить `provider_response_payload` и HTTP-код провайдера.
5. Для видео проверить polling по `request_id` и наличие сохранённого файла в private-хранилище.
6. Для стоимости проверить свежесть агрегатов в `ai_costs`.

## Типы задач (AiTaskType)

Полный перечень в `app/Enums/AiTaskType.php`:

- Текст: `generate_description`, `rewrite_text`, `rewrite_ozon`, `rewrite_wb`, `adapt_wb`, `adapt_ozon`, `generate_ozon_rich`, `rich_description`
- Изображения: `generate_image`, `edit_image`
- Видео: `generate_video`, `generate_video_from_image`
- Смежные: `wb_feedback_answer_ai`, `ozon_feedback_answer_ai`, `wb_ai_cabinet_analyzer_ai`

## Контракт Grok Video (для фронта)

Входные поля для `POST /subscriber/ai/video/start`:

- `task_type`: `generate_video` | `generate_video_from_image`
- `prompt`: string
- `duration`: integer (1..15, optional)
- `resolution`: `480p` | `720p` (optional)
- `aspect_ratio`: `1:1` | `16:9` | `9:16` | `4:3` | `3:4` | `3:2` | `2:3` (только для text-to-video, default `16:9`)
- `image`: data URI/base64 (обязательно для `generate_video_from_image`)

Для scene/reference-to-video: `POST /subscriber/ai/video/reference/start` — до 7 изображений, `duration` до 10 сек.

Polling: `GET /subscriber/ai/video/status/{request_id}` — статусы `pending`, `done`, `expired`, `filtered_by_moderation`. Лимит `AI_VIDEO_QUERY` списывается один раз при первом `done`.
