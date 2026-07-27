# WB AiCabinet Analyzer

## Права доступа

- Permission: `subscriber wb ai cabinet analyzer`
- Middleware панели: `auth`, `verified`, `panel.access`, `wb.cabinets.migrated`, `permission:subscriber wb ai cabinet analyzer`
- Admin: `role:Супер-Админ|super-admin` (без отдельного permission)

## Назначение

Инструмент для анализа рекламных кампаний Wildberries в формате snapshot-отчёта.

Сценарий:

1. Пользователь выбирает **единый** кабинет WB в шапке ([wb-cabinets.md](wb-cabinets.md)).
2. Открывает workspace `/panel/wb/ai-cabinet-analyzer`.
3. Запускает анализ за период (кабинет = selected `wb_cabinets`).
4. Backend собирает полную номенклатуру кабинета (карточки WB).
5. Backend получает воронку продаж за период по всей номенклатуре (строгий лимит 1 запрос/мин).
6. Backend собирает кампании, NMID и статистику по рекламе.
7. Backend объединяет данные рекламы и воронки в `items`.
8. Формируется snapshot-отчёт и сохраняется в `wb_ai_cabinet_analyzer_reports.result_json` с `cabinet_id` = `wb_cabinets.id`.

Отдельного CRUD «кабинетов AI Analyzer» в UI больше нет.

## Ключевые файлы

### Web (Inertia)

- `app/Http/Controllers/Web/Subscriber/Wb/AiCabinetAnalyzer/WorkspaceController.php`
- `app/Http/Controllers/Web/Subscriber/Wb/AiCabinetAnalyzer/CabinetsController.php`
- `app/Http/Controllers/Web/Subscriber/Wb/AiCabinetAnalyzer/AiAnalysesController.php` (если выделен)
- Trait: `ResolvesSelectedWbCabinet`

### Services / Jobs

- `app/Services/Subscriber/Wb/WbAiCabinetAnalyzerCabinetsService.php`
- `app/Services/Subscriber/Wb/WbAiCabinetAnalyzerReportsService.php`
- `app/Services/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerService.php`
- `app/Services/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerAiAnalysisService.php`
- `app/Services/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerPdfGenerator.php`
- `app/Services/Wb/AiCabinetAnalyzer/ReviewProductStatisticAggregator.php`
- `app/Jobs/Wb/AiCabinetAnalyzer/ProcessAiCabinetAnalyzerReport.php`
- `app/Jobs/Wb/AiCabinetAnalyzer/ProcessAiCabinetAnalyzerAiAnalysisJob.php`

### Модели

- `app/Models/Subscribers/Wb/WbCabinet.php` — единый кабинет / API-ключ
- `app/Models/Subscribers/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerCabinet.php` — legacy (миграция)
- `app/Models/Subscribers/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerReport.php` — `cabinet_id` → unified
- `app/Models/Subscribers/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerTemplate.php`
- `app/Models/Subscribers/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerAiAnalysis.php`

### Config / admin

- `config/ai_cabinet_analyzer.php`
- `app/Services/Admin/AdminAiCabinetService.php`
- `database/seeders/AiCabinetAnalyzerTemplatesSeeder.php`

## Web routes (Inertia)

Prefix: `/panel/wb/ai-cabinet-analyzer` · name: `subscriber.wb.ai-cabinet-analyzer.*`

| Method | URL | Named route | Назначение |
|--------|-----|-------------|------------|
| GET | `/` | `index` | `Subscriber/Wb/AiCabinetAnalyzer/Cabinet/Show` |
| POST | `/reports` | `reports.store` | Запуск snapshot-отчёта |
| POST | `/ai-analyses/start` | `ai-analyses.start` | Старт AI-анализа |
| POST | `/ai-analyses/{analysis}/regenerate` | `ai-analyses.regenerate` | Перегенерация |
| GET | `/ai-analyses/{analysis}` | `ai-analyses.show` | Статус/результат (JSON) |
| GET | `/ai-analyses/{analysis}/download` | `ai-analyses.download` | PDF |

Редирект legacy: `/cabinets/{cabinet}` → `/panel/wb/ai-cabinet-analyzer`.

При отсутствии выбранного кабинета — `Subscriber/Wb/Shared/NoCabinet`.

Запуск отчёта использует selected cabinet; параметр `cabinet_id` в body не требуется (или игнорируется в пользу selected).

## Admin (web)

- `/cw-page/services/ai-cabinet/*` — кабинеты, шаблоны промптов

## Технические детали

- Источник Ads: `https://advert-api.wildberries.ru`.
- Авторизация: `Authorization` с ключом из `wb_cabinets.apikey`.
- Методы WB API:
    - `/adv/v1/promotion/count`
    - `/api/advert/v2/adverts`
    - `/adv/v3/fullstats`
- Дополнительно:
    - `POST https://content-api.wildberries.ru/content/v2/get/cards/list`
    - `POST https://seller-analytics-api.wildberries.ru/api/analytics/v3/sales-funnel/products`
- Батчи `ids`: до 50.
- Лимиты персонального токена: не более 3 запросов/мин и минимум 20 с между запросами.
- Sales funnel: 1 запрос в минуту.
- Retry/backoff: 429/5xx; при 429 — пауза ≥ 20 с.
- Статусы отчёта: `processing | done | failed`.
- Структура `result_json`: `meta`, `campaigns`, `items`.
- В `items` для каждого `nmid`: `vendorCode`, `image`, ads metrics, `funnel`, `ads_vs_funnel`.
- `result_json` — `LONGTEXT`, cast `array`.
- `cabinet_id` в reports = `wb_cabinets.id` (после миграции; legacy FK на analyzer-cabinets снят/переписан).
- AI-анализ только по snapshot в `result_json` (без повторных WB-запросов).
- Шаблоны: `wb_ai_cabinet_analyzer_templates`.
- AI-анализы: `wb_ai_cabinet_analyzer_ai_analyses` (`processing|done|failed`).
- AI: `GeminiApiClient` + fallback GPT (`APP_GPT_KEY`), очередь `wb_profit_analyzer`.
- Модель по умолчанию: `gemini`.
- Пустой итоговый AI-результат → `failed`, не `done`.
- Большие отчёты: батчинг dataset → единый результат.
- В API-ответах `analysis_text` — JSON-структура; `analysis_json` / `model` на фронт не отдаются.
- `analysis_text.metrics`: массив `{key,label,value}`, `label` на русском.

## Очереди

Обязательная очередь: `wb_profit_analyzer`.

```bash
php artisan queue:work --queue=wb_profit_analyzer --tries=3 --timeout=3600 --sleep=1
```

Без воркера отчёты остаются в `processing`.

## Связанные документы

- [wb-cabinets.md](wb-cabinets.md)
- [wb-ai-cabinet-analyzer-sales-funnel-fields.md](wb-ai-cabinet-analyzer-sales-funnel-fields.md)
