# Ozon AI Cabinet Analyzer

## Права доступа

- Permission: `subscriber oz ai cabinet analyzer`
- Middleware панели: `auth`, `verified`, `panel.access`, `permission:subscriber oz ai cabinet analyzer`
- Admin: `role:Супер-Админ|super-admin` (без отдельного permission)

## Назначение

Инструмент для snapshot-анализа кабинета Ozon (архитектура зеркалит [WB AI Cabinet Analyzer](wb-ai-cabinet-analyzer.md)).

### Этап 2 (текущий)

При запуске анализа:

1. Берётся **единый** кабинет Ozon из шапки (`oz_cabinets` / `selected_oz_cabinet_id`).
2. В очереди `oz_ai_cabinet_analyzer` собираются:
   - полный **каталог** товаров;
   - **free-аналитика** (`revenue`, `ordered_units` по SKU);
   - **поисковый спрос** (product-queries, free-поля);
   - **остатки / оборачиваемость**;
   - **реклама** (Performance API) — если заданы Performance-ключи.
3. Данные **агрегируются в одну запись на product_id**.
4. Snapshot сохраняется в `oz_ai_cabinet_analyzer_reports.result_json`.

**Не собираются:** отзывы, Premium-метрики воронки (hits_view*, hits_tocart*, conv_*, returns, cancellations, delivered_units, position_category).

### Почему нет полной воронки как у WB

В `POST /v1/analytics/data` без подписки **Premium** доступны только `revenue` и `ordered_units`.  
Показы, корзины, конверсии, выкупы, отмены — **Premium-only**. По ТЗ платные/недоступные большинству методы не используются.

### Сценарий пользователя

1. Выбрать кабинет Ozon в шапке (опционально указать Performance API ключи для рекламы).
2. Открыть `/panel/oz/ai-cabinet-analyzer`.
3. Запустить сбор за период.
4. После `done` — таблица товаров (каталог + аналитика + реклама) + ИИ-анализ по шаблону.

## Ключевые файлы

### Web (Inertia)

- `app/Http/Controllers/Web/Subscriber/Oz/AiCabinetAnalyzer/WorkspaceController.php`
- `app/Http/Controllers/Web/Subscriber/Oz/AiCabinetAnalyzer/AiAnalysesController.php`
- Trait: `ResolvesSelectedOzCabinet`

### Services / Jobs

- `OzAiCabinetAnalyzerService` — оркестратор
- Collectors:
  - `OzAiCabinetAnalyzerProductsCollector`
  - `OzAiCabinetAnalyzerAnalyticsCollector`
  - `OzAiCabinetAnalyzerProductQueriesCollector`
  - `OzAiCabinetAnalyzerStocksCollector`
  - `OzAiCabinetAnalyzerAdsCollector`
- HTTP: `OzonApiService`, `OzonPerformanceApiService`
- Jobs: `ProcessOzAiCabinetAnalyzerReport`, `ProcessOzAiCabinetAnalyzerAiAnalysisJob`

## Ozon Seller API (используемые методы)

База: `https://api-seller.ozon.ru/` · headers: `Client-Id`, `Api-Key`

| Метод | Назначение | Free |
|-------|------------|------|
| `POST /v3/product/list` | Список товаров | да |
| `POST /v3/product/info/list` | Карточки | да |
| `POST /v4/product/info/attributes` | Бренд (best-effort) | да |
| `POST /v1/analytics/data` | revenue, ordered_units by sku | free-метрики only |
| `POST /v1/analytics/product-queries` | поисковый спрос | частично |
| `POST /v2/analytics/stock_on_warehouses` | остатки | да |
| `POST /v1/analytics/turnover/stocks` | IDC / ADS | да |

**Ограничения free analytics:** глубина ~3 месяца; max 1000 строк/запрос; throttle + 429 backoff 60s.

## Performance API (реклама)

Документация: https://docs.ozon.ru/api/performance/

| | |
|--|--|
| Base | `https://api-performance.ozon.ru` (не `performance.ozon.ru`) |
| Auth | `POST /api/client/token` → Bearer (`client_credentials`) |
| Credentials | **отдельные** Client ID + Client Secret: **Настройки → Performance API** |
| Seller API | **не** открывает рекламу; в ролях ключа Seller нет пункта «реклама» |

Хранение в `oz_cabinets`: `performance_client_id`, `performance_client_secret` (encrypt, optional).

Методы сбора:

- `GET /api/client/campaign`
- `GET /api/client/statistics/campaign/product` (sync, SKU-level: views, clicks, expense, orders, sales, toCart)

Без ключей: `advertising_status = skipped_no_credentials`, snapshot всё равно `done`.

## Snapshot `result_json`

```json
{
  "meta": {
    "generated_at": "...",
    "period": { "begin_date": "Y-m-d", "end_date": "Y-m-d" },
    "analytics_period": { "begin_date": "Y-m-d", "end_date": "Y-m-d" },
    "period_clamped": false,
    "sources_collected": ["products", "analytics", "product_queries", "stocks", "advertising"],
    "analytics_tier": "free",
    "premium_metrics_excluded": true,
    "advertising_status": "collected|skipped_no_credentials|failed",
    "products_count": 0,
    "warnings": [],
    "api": {}
  },
  "campaigns": [],
  "products": [
    {
      "product_id": 1,
      "offer_id": "...",
      "sku": 1,
      "skus": { "fbo": null, "fbs": null, "all": [] },
      "name": "...",
      "brand": null,
      "raw": {},
      "analytics": { "revenue": 0, "ordered_units": 0, "period": {} },
      "search": { "unique_search_users": 0, "gmv": 0 },
      "stocks": { "free_to_sell": 0, "reserved": 0, "promised": 0 },
      "turnover": { "ads": 0, "current_stock": 0, "idc": 0 },
      "advertising": { "views": 0, "clicks": 0, "spend": 0, "orders": 0 },
      "ads_vs_analytics": { "orders_gap": 0, "orders_ratio_ads_to_analytics": null }
    }
  ]
}
```

Join: analytics/ads/stocks по **SKU** → `products[].skus.all`.

- Статусы отчёта: `processing | done | failed`
- AI `data_sources`: `products`, `analytics`, `search`, `stocks`, `advertising`
- `ads_vs_analytics` в LLM только если выбраны analytics **и** advertising
- AI: Gemini + GPT fallback, `AiTaskType::OZ_AI_CABINET_ANALYZER_AI`

## Очереди

```bash
php artisan queue:work --queue=oz_ai_cabinet_analyzer --tries=3 --timeout=3600 --sleep=1
```

`QUEUE_RETRY_AFTER` должен быть **> 3600** (по умолчанию 3700), иначе database-очередь дублирует длинные job. Подробности: [queues.md](queues.md), [wb-ai-cabinet-analyzer.md](wb-ai-cabinet-analyzer.md) (раздел про дубли запросов к ИИ).

## Связанные документы

- [oz-cabinets.md](oz-cabinets.md)
- [wb-ai-cabinet-analyzer.md](wb-ai-cabinet-analyzer.md)
