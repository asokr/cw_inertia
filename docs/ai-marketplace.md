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
- редактирование загруженного ролика;
- проверка баланса кредитов и списание через единый каталог стоимости;
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
- проверка баланса кредитов до вызова провайдера;
- списание `generate_text` после успешного результата;
- логирование токенов и текста ответа.
- при недоступности Gemini включается fallback на ChatGPT (`/v1/chat/completions`) — одно списание.

### Image-задачи (Gemini)

- `generate_image`
- `edit_image`

Особенности:

- поддержка нескольких входных референсов (`images[]`);
- ограничение входного изображения до 10MB;
- поддержка параметров `aspectRatio`, `resolution`;
- стоимость в кредитах берётся из каталога `generate_image` / `edit_image` по `resolution` (не хардкод).
- при недоступности Gemini включается fallback на Grok Image API:
    - без входных изображений: `POST /v1/images/generations`;
    - с входными изображениями: `POST /v1/images/edits`;
    - входные изображения передаются в base64 (`data URI`) и поддерживается несколько изображений.

### Видео-задачи (Grok)

- text-to-video;
- image-to-video;
- scene/reference-to-video (`reference/start`, 1..7 изображений);
- редактирование ролика (`edit/start`): исходник грузится в xAI Files API, затем `POST /v1/videos/edits`.

Особенности:

- отдельный запуск и отдельный опрос статуса по `request_id`;
- стоимость — каталог `generate_video`: цена секунды выбранного разрешения × длительность;
- перед стартом кредиты резервируются, на первом успешном `done` списываются, при ошибке/модерации/истечении возвращаются;
- при `done` видео сохраняется во внутреннее private-хранилище и отдается через backend endpoint;
- для `generate_video_from_image` и сцены можно передавать URL, data URI, base64 и пути из истории (`/panel/ai/media/...`);
- бинарные входные изображения не кладутся в JSON: они загружаются в xAI Files API (`POST /v1/files`) и в генерацию передаётся `file_id`. Публичные HTTP(S) URL уходят как `url`;
- для сцены все входные референсы сохраняются в `source_images` задачи и отдаются в истории сессии полем `images` (даже если запуск у провайдера не удался и нет `request_id`). В ленте справа только сгенерированное видео; кадры сцены показываются рядом с промптом. Повторная генерация из истории отправляет эти пути — бэкенд читает файлы с диска и грузит их в Files API.
- для редактирования исходный ролик сохраняется и отдаётся полем `source_video` (в `images` не попадает). В ленте справа только результат; исходник — рядом с промптом. Повтор из истории шлёт panel-путь. Если открыть готовый ролик и переключить режим на «Редактирование», в форму подставляется этот результат.

Лимиты xAI Video API, которые учитываем:

| Ограничение | Значение | Как обрабатываем |
|-------------|----------|------------------|
| JSON-тело `POST /v1/videos/generations` | шлюз отклоняет крупный payload ошибкой `The POST data is too large` (типичный потолок ~4 МБ, gRPC-транс코딩) | base64 не отправляем; перед запросом проверяем размер JSON (`GROK_MAX_VIDEO_JSON_BYTES`, по умолчанию 3 МБ) |
| Входное изображение | JPEG / PNG / WebP; для Files API до 50 МБ, у нас пользовательский лимит 10 МБ | валидация на фронте и бэкенде; сверх лимита — понятное сообщение |
| Image-to-video | 1 изображение; duration 1–15 с; 480p / 720p | как раньше |
| Reference-to-video | 1–7 изображений; duration до 10 с на `grok-imagine-video`; resolution не выше 720p | как раньше |
| Редактирование видео | MP4; кодек H.264 / H.265 / AV1; не длиннее 8,7 с; `duration` / `aspect_ratio` / `resolution` у провайдера игнорируются (выход как у входа, потолок 720p) | проверяем контейнер, кодек и длительность до Files API (`Mp4MediaProbe`); пользовательский лимит файла 25 МБ (`AI_MEDIA_MAX_SOURCE_VIDEO_BYTES`); на Grok уходит `file_id` |
| Files API | `expires_after` 1 час…30 дней, поле должно идти **до** `file` | TTL по умолчанию 3600 с (`GROK_FILES_EXPIRES_AFTER`) |
| TLS к api.x.ai / vidgen.x.ai | PHP cURL на Windows/OSPanel может не доверять цепочке (антивирус MITM, ошибка `cURL error 60`) | локально `GROK_HTTP_VERIFY=false` и для API, и для скачивания готового ролика; в проде оставить `true` |

## Subscriber API

### 1) `POST /subscriber/ai/marketplace`

Единая точка входа для текста и изображений.

Назначение:

- принять `task_type` и входные параметры;
- провалидировать payload;
- проверить баланс кредитов;
- вызвать Gemini;
- сохранить лог запроса/ответа;
- вернуть результат и обновлённый остаток кредитов.

Ключевые поля ответа:

- `success`, `messages`, `data`;
- результат задачи (`text` и/или `images`);
- для image-задач `images` содержит массив URL на внутренние media endpoints (не base64);
- актуальный остаток кредитов (`credits`, `credits_charged`).

### 2) `POST /subscriber/ai/video/start`

Старт видео-задачи (text-to-video/image-to-video).

Параметры (основные):

- `task_type`;
- `prompt`;
- `duration`;
- `resolution` (`480p|720p`);
- `aspect_ratio` (или alias `aspectRatio`);
- `image` (для image-to-video, до 10 МБ; на Grok уходит как `file_id` или публичный URL).

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

### 4) `POST /panel/ai/video/edit/start`

Старт редактирования ролика.

Параметры:

- `prompt`;
- `video`: multipart MP4 **или** путь `/panel/ai/media/...` из истории (свой файл).

Дополнительная валидация до Grok:

- только MP4;
- длительность ≤ 8,7 с;
- кодек H.264 / H.265 / AV1;
- размер до 25 МБ;
- panel-путь принадлежит текущему пользователю.

Стоимость — каталог `generate_video`: `ceil(длительность)` × разрешение по высоте кадра (≤480 → 480p, иначе 720p). Параметры длительности/качества/формата на выход не передаются.

Опрос статуса — тот же `GET /panel/ai/video/status/{request_id}`.

### 5) `GET /subscriber/ai/video/status/{request_id}`

Проверка состояния видео-задачи.

Обработка статусов:

- `pending`: задача ещё обрабатывается;
- `done`: видео готово, возвращается `video.url` (внутренний backend URL) и `provider_url`;
- `expired`: возвращается понятная ошибка;
- `filtered_by_moderation`: отдельное сообщение, что контент не прошёл модерацию.

### 6) `GET /subscriber/ai/media/{path}`

Выдача private AI media для владельца файла.

Особенности:

- доступ только для авторизованного пользователя;
- разрешены только пути под `image_prefix` / `video_prefix` / `source_video_prefix`;
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
2. Контроллер валидирует payload и баланс кредитов.
3. Вызывается `GeminiApiClient`.
4. При ошибке недоступности Gemini выполняется fallback:
    - текст → `OpenAiTextFallbackClient`;
    - изображения → `GrokImageApiClient`.
5. Результат нормализуется (text/images).
6. Списываются кредиты (`spend`) один раз за успешную операцию.
7. Лог пишется в `ai_request_logs`.
8. Возвращается ответ с обновлённым остатком кредитов.

### Для видео

1. Клиент вызывает `POST /panel/ai/video/start`, `POST /panel/ai/video/reference/start` или `POST /panel/ai/video/edit/start`.
2. Контроллер валидирует payload (для редактирования — MP4, кодек и длительность) и резервирует кредиты.
3. Входные изображения или исходный ролик при необходимости сохраняются через `AiMediaStorageService`.
4. `GrokVideoApiClient` запускает задачу у провайдера (`/v1/videos/generations` или `/v1/videos/edits`).
5. Клиент опрашивает `GET /panel/ai/video/status/{request_id}`.
6. При первом `done` видео сохраняется в private-хранилище, резерв списывается один раз.
7. При ошибке, модерации или истечении резерв возвращается.

## Лимиты и тарификация

Используется единый баланс кредитов, см. [credits-billing.md](credits-billing.md).

Стоимость читается из каталога `/cw-page/credit-pricing` через `CreditPriceCalculator::quote`:

- текст — `generate_text`;
- картинка — `generate_image` или `edit_image` + `resolution`;
- видео — `generate_video` + `resolution` + `duration`.

Контроллеры всегда:

- проверяют доступные кредиты до обращения к провайдеру;
- для видео резервируют кредиты до запуска Grok;
- списывают только после фактически выполненной операции;
- не принимают стоимость с frontend.

Inertia-страницы получают проп `pricing`. Точную сумму можно запросить `POST /panel/ai/quote`.

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
- сцена (reference-to-video) сохраняет **все** загруженные изображения, не только первое; при открытии сессии из истории они возвращаются в `tasks[].images`;
- доступ к файлам только через backend endpoints (`/subscriber/ai/media/{path}`, `/admin/services/ai/media/{path}`);
- медиафайлы удаляются при удалении генерации пользователем через `AiMediaStorageService` (`deleteTaskMedia`, `deleteImageTaskMedia`).

## Ошибки и устойчивость

- для Gemini high-load/429 возвращается понятное сообщение о временной перегрузке;
- видео-статусы Grok интерпретируются в пользовательские сообщения;
- `The POST data is too large` / HTTP 413 показывается как «Изображение слишком большое для создания видео. Загрузите файл меньшего размера.» — сырой текст провайдера пользователю не отдаём;
- все внешние ошибки логируются с расширенным контекстом для диагностики;
- при частичных ошибках провайдера сохраняется максимально полезный диагностический payload.

## Эксплуатационные заметки

- для корректной работы должны быть настроены ключи и URL провайдеров в `config/services.php`;
- воркер очередей должен обслуживать задачи, связанные с AI и смежными сервисами;
- рекомендуется следить за ретеншеном логов и размером private-хранилища;
- для финансовой аналитики важно, чтобы команда агрегации расходов запускалась регулярно по расписанию.

## Быстрый чек-лист при проблемах

1. Проверить баланс кредитов пользователя.
2. Проверить валидность входного payload (особенно размер/формат image).
3. Проверить `ai_request_logs` по пользователю и `task_type`.
4. Проверить `provider_response_payload` и HTTP-код провайдера.
5. Для видео проверить polling по `request_id` и наличие сохранённого файла в private-хранилище.
6. Для стоимости проверить свежесть агрегатов в `ai_costs`.

## Типы задач (AiTaskType)

Полный перечень в `app/Enums/AiTaskType.php`:

- Текст: `generate_description`, `rewrite_text`, `rewrite_ozon`, `rewrite_wb`, `adapt_wb`, `adapt_ozon`, `generate_ozon_rich`, `rich_description`
- Изображения: `generate_image`, `edit_image`
- Видео: `generate_video`, `generate_video_from_image`, `generate_video_from_scene`, `edit_video`
- Смежные: `wb_feedback_answer_ai`, `ozon_feedback_answer_ai`, `wb_ai_cabinet_analyzer_ai`

## Контракт Grok Video (для фронта)

Входные поля для `POST /subscriber/ai/video/start`:

- `task_type`: `generate_video` | `generate_video_from_image`
- `prompt`: string
- `duration`: integer (1..15, optional)
- `resolution`: `480p` | `720p` (optional)
- `aspect_ratio`: `1:1` | `16:9` | `9:16` | `4:3` | `3:4` | `3:2` | `2:3` (только для text-to-video, default `16:9`)
- `image`: data URI/base64 (обязательно для `generate_video_from_image`)

Для scene/reference-to-video: `POST /panel/ai/video/reference/start` — до 7 изображений, `duration` до 10 сек. Входные картинки на Grok уходят как `file_id` (Files API), а не как base64 в JSON.

Для редактирования: `POST /panel/ai/video/edit/start` — `prompt` + `video` (multipart MP4 или путь из истории). Длительность/качество/формат не передаются. Исходник на Grok уходит как `file_id`.

Polling: `GET /panel/ai/video/status/{request_id}` — статусы `pending`, `done`, `expired`, `filtered_by_moderation`. Кредиты списываются один раз при первом `done`.
