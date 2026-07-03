# WB AiCabinet Analyzer

## Права доступа

- Permission: `subscriber wb ai cabinet analyzer`
- Middleware: `auth:api`, `verified`, `role:Подписчик`
- Admin: `role:Супер-Админ|super-admin` (без отдельного permission)

## Назначение

Инструмент для анализа рекламных кампаний Wildberries в формате snapshot-отчёта.

Сценарий:

1. Пользователь создаёт/выбирает кабинет WB AiCabinet Analyzer.
2. Передаёт `cabinet_id` в запуск отчёта AiCabinet Analyzer.
3. Запускает анализ за период.
4. Backend собирает полную номенклатуру кабинета (карточки WB).
5. Backend получает воронку продаж за период по всей номенклатуре (строгий лимит 1 запрос/мин).
6. Backend собирает кампании, NMID и статистику по рекламе.
7. Backend объединяет данные рекламы и воронки в `items`.
8. Формируется snapshot-отчёт и сохраняется в `wb_ai_cabinet_analyzer_reports.result_json`.

## Ключевые файлы

- `app/Models/Subscribers/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerCabinet.php`
- `app/Models/Subscribers/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerReport.php`
- `app/Models/Subscribers/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerTemplate.php`
- `app/Models/Subscribers/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerAiAnalysis.php`
- `app/Services/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerService.php`
- `app/Services/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerAiAnalysisService.php`
- `app/Services/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerPdfGenerator.php`
- `app/Services/Wb/AiCabinetAnalyzer/ReviewProductStatisticAggregator.php`
- `app/Jobs/Wb/AiCabinetAnalyzer/ProcessAiCabinetAnalyzerReport.php`
- `app/Jobs/Wb/AiCabinetAnalyzer/ProcessAiCabinetAnalyzerAiAnalysisJob.php`
- `app/Http/Controllers/Api/Subscriber/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerCabinetsController.php`
- `app/Http/Controllers/Api/Subscriber/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerReportsController.php`
- `app/Http/Controllers/Api/Subscriber/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerAiAnalysesController.php`
- `app/Http/Controllers/Api/Admin/services/aicabinetanalyzer/AdminAiCabinetAnalyzerController.php`
- `config/ai_cabinet_analyzer.php`
- `database/seeders/AiCabinetAnalyzerTemplatesSeeder.php`
- `database/migrations/2026_05_25_120000_create_wb_ai_cabinet_analyzer_templates_table.php`
- `database/migrations/2026_05_25_120100_create_wb_ai_cabinet_analyzer_ai_analyses_table.php`

- `database/migrations/2026_05_04_120000_create_wb_ai_cabinet_analyzer_cabinets_table.php`
- `database/migrations/2026_05_04_120100_create_wb_ai_cabinet_analyzer_reports_table.php`
- `database/migrations/2026_05_19_140000_restore_wb_ai_cabinet_analyzer_cabinets_and_reports_cabinet_id.php`

## Эндпоинты

### Кабинеты

- `GET /subscriber/wb/ai-cabinet-analyzer/cabinets`
- `POST /subscriber/wb/ai-cabinet-analyzer/cabinets`
- `GET /subscriber/wb/ai-cabinet-analyzer/cabinets/{id}`
- `PUT/PATCH /subscriber/wb/ai-cabinet-analyzer/cabinets/{id}`
- `DELETE /subscriber/wb/ai-cabinet-analyzer/cabinets/{id}`

### Отчёты

- `POST /subscriber/wb/ai-cabinet-analyzer/reports/start`
- `GET /subscriber/wb/ai-cabinet-analyzer/reports/latest/{cabinet_id}`
- `GET /subscriber/wb/ai-cabinet-analyzer/reports/{report}/nomenclatures` — пагинированный список номенклатур из `result_json.items`
- `GET /subscriber/wb/ai-cabinet-analyzer/reports/{report}/nomenclatures/search` — поиск номенклатур по `nmid` и/или `advert_id` с пагинацией
- `GET /subscriber/wb/ai-cabinet-analyzer/reports/{report}`
- `GET /subscriber/wb/ai-cabinet-analyzer/reports/{report}/status`

### AI-анализы

- `GET /subscriber/wb/ai-cabinet-analyzer/ai-templates`
- `POST /subscriber/wb/ai-cabinet-analyzer/ai-analyses/start`
- `GET /subscriber/wb/ai-cabinet-analyzer/reports/{report}/ai-analyses`
- `POST /subscriber/wb/ai-cabinet-analyzer/ai-analyses/{analysis}/regenerate`
- `GET /subscriber/wb/ai-cabinet-analyzer/ai-analyses/{analysis}`
- `GET /subscriber/wb/ai-cabinet-analyzer/ai-analyses/{analysis}/download` — скачивание PDF-отчёта
    - В API-ответах `analysis_text` отдаётся на фронт как JSON-структура (декодируется из сохранённой строки), служебные поля хранения `analysis_json` и `model` на фронт не возвращаются.
    - Блок `analysis_text.metrics` формируется моделью в формате массива объектов `{key,label,value}`, где `label` всегда на русском языке.

### Admin API

- `GET /admin/services/ai-cabinet-analyzer/cabinets` — список кабинетов
- `GET /admin/services/ai-cabinet-analyzer/templates` — список промптов
- `POST /admin/services/ai-cabinet-analyzer/templates` — создание промпта
- `PUT /admin/services/ai-cabinet-analyzer/templates/{id}` — обновление
- `DELETE /admin/services/ai-cabinet-analyzer/templates/{id}` — удаление

## Технические детали

- Источник данных: `https://advert-api.wildberries.ru`.
- Авторизация: передаём только `Authorization`.
- Используемые методы WB API:
    - `/adv/v1/promotion/count`
    - `/api/advert/v2/adverts`
    - `/adv/v3/fullstats`
- Дополнительно использует:
    - `POST https://content-api.wildberries.ru/content/v2/get/cards/list` (полная номенклатура кабинета)
    - `POST https://seller-analytics-api.wildberries.ru/api/analytics/v3/sales-funnel/products` (воронка продаж за период)
- Батчи `ids`: до 50.
- Лимиты персонального токена (учитываются в сервисе): не более 3 запросов в минуту и минимум 20 секунд между запросами.
- Для sales funnel применяется отдельный строгий лимит: 1 запрос в минуту.
- Retry/backoff: для 429/5xx и сетевых ошибок, при 429 — пауза не меньше 20 секунд.
- Статусы отчёта: `processing | done | failed`.
- Для запуска отчёта `POST /subscriber/wb/ai-cabinet-analyzer/reports/start` требуется обязательный параметр `cabinet_id` (ID кабинета AiCabinet Analyzer пользователя).
- Для получения последнего актуального анализа `GET /subscriber/wb/ai-cabinet-analyzer/reports/latest/{cabinet_id}` возвращается последний отчёт в статусе `done` по кабинету пользователя.
- Для просмотра номенклатур реализована Laravel-пагинация через `GET /subscriber/wb/ai-cabinet-analyzer/reports/{report}/nomenclatures` (`page`, `per_page`).
- Для поиска по списку номенклатур реализован endpoint `GET /subscriber/wb/ai-cabinet-analyzer/reports/{report}/nomenclatures/search` с фильтрами `nmid` и `advert_id`.
- API-ключ для Ads-запросов берётся из выбранного кабинета AiCabinet Analyzer.
- Структура `result_json`: `meta`, `campaigns`, `items`.
- В `items` для каждого `nmid` сохраняются:
    - `vendorCode` — артикул продавца (vendor code) из карточки WB, если доступен в кабинете;
    - `image` — URL первого изображения товара (формируется по `nmid` и сохраняется в snapshot на этапе сборки отчёта);
    - агрегированные рекламные поля (`clicks`, `views`, `spend`, `orders`, `ctr`, `cpc`, `cr`);
    - блок `funnel` (нормализованные KPI воронки + `raw_funnel_payload`);
    - блок `ads_vs_funnel` (сопоставление рекламы и воронки, например `orders_gap`, `orders_ratio_ads_to_funnel`).
- Поле `result_json` в таблице `wb_ai_cabinet_analyzer_reports` хранится в типе `LONGTEXT`; в модели используется cast `result_json => array`.
- В таблице `wb_ai_cabinet_analyzer_reports` используется поле `cabinet_id` (FK на `wb_ai_cabinet_analyzer_cabinets`).
- Для AI-анализа используется только ранее подготовленный dataset из `wb_ai_cabinet_analyzer_reports.result_json`; повторный сбор snapshot и запросы к WB API не выполняются.
- Шаблоны AI-анализа хранятся в таблице `wb_ai_cabinet_analyzer_templates` (поля: `id`, `name`, `description`, `system_prompt`, `sort_order`, `is_active`).
- Результаты AI-анализа хранятся в таблице `wb_ai_cabinet_analyzer_ai_analyses` (статусы `processing|done|failed`, `analysis_text`, `analysis_json`, токены, ошибки выполнения).
- AI-анализ выполняется через `GeminiApiClient` с fallback на GPT (`APP_GPT_KEY`) в фоне через очередь `wb_profit_analyzer`.
- Модель AI по умолчанию для инструмента: `gemini`.
- Если Gemini вернул ошибку, пустой ответ или невалидный JSON, автоматически выполняется повтор того же запроса в OpenAI Chat Completions (GPT).
- Если итоговый AI-результат после всех попыток пустой (`analysis_text` пуст и отсутствует содержимое в `analysis_json`), запись не переводится в `done`: job завершает анализ статусом `failed` с `error_message`.
- Перед отправкой в Gemini dataset нормализуется: в payload исключаются служебные/технические блоки, не участвующие в анализе (`meta.api`, warning/debug/raw-поля и аналогичные).
- Для больших отчётов применяется автоматический батчинг: dataset разбивается на части, каждая часть анализируется отдельно, после чего формируется единый итоговый результат и сохраняется в одну запись `wb_ai_cabinet_analyzer_ai_analyses`.

## Очереди, которые должны работать

- Обязательная очередь для инструмента: `wb_profit_analyzer`.
- Именно в эту очередь ставится job `ProcessAiCabinetAnalyzerReport` из `AiCabinetAnalyzerReportsController@start`.
- Если воркер очереди `wb_profit_analyzer` не запущен, отчёты будут оставаться в статусе `processing`.

## Команда запуска для dev-режима

Запускать отдельный воркер именно для очереди AiCabinet Analyzer:

`php artisan queue:work --queue=wb_profit_analyzer --tries=3 --timeout=3600 --sleep=1`

Дополнительно:

- Для локальной отладки удобно запускать в отдельном терминале, чтобы видеть ошибки job в реальном времени.
- Если используете Supervisor/Horizon в проде, очередь `wb_profit_analyzer` должна быть явно добавлена в конфиг процессов.

## Связанные документы

- [wb-ai-cabinet-analyzer-sales-funnel-fields.md](wb-ai-cabinet-analyzer-sales-funnel-fields.md)
