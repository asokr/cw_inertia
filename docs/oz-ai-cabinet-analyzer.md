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
   - полный **каталог** товаров (включая индекс цен, комиссии, ошибки карточки);
   - **free-аналитика** (`revenue`, `ordered_units` по SKU);
   - **поисковый спрос** (product-queries + тексты запросов details, free-поля);
   - **остатки / оборачиваемость / ликвидность**;
   - **контент-рейтинг** карточек;
   - **рейтинги продавца**;
   - **акции** Ozon;
   - **реклама** (Performance API) — если заданы Performance-ключи: CPC по SKU за период + оплата за заказ.
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

На workspace после `done` сверху показывается счётчик «Товаров» и блок «Рейтинги продавца» (Premium / Premium Plus, штрафные баллы и показатели Ozon). Сырой статус API `UNKNOWN_STATUS` не выводится; `OK` / `WARNING` / `CRITICAL` показываются как «норма» / «внимание» / «критично». Рядом с заголовком блока — иконка-подсказка, что означают показатели.

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
  - `OzAiCabinetAnalyzerContentRatingCollector`
  - `OzAiCabinetAnalyzerSellerRatingCollector`
  - `OzAiCabinetAnalyzerPromosCollector`
- HTTP: `OzonApiService`, `OzonPerformanceApiService`
- Jobs: `ProcessOzAiCabinetAnalyzerReport`, `ProcessOzAiCabinetAnalyzerAiAnalysisJob`

## Ozon Seller API (используемые методы)

База: `https://api-seller.ozon.ru/` · headers: `Client-Id`, `Api-Key`

| Метод | Назначение | Free |
|-------|------------|------|
| `POST /v3/product/list` | Список товаров | да |
| `POST /v3/product/info/list` | Карточки + индекс цен, комиссии, ошибки | да |
| `POST /v4/product/info/attributes` | Бренд (best-effort) | да |
| `POST /v1/analytics/data` | revenue, ordered_units by sku | free-метрики only |
| `POST /v1/analytics/product-queries` | поисковый спрос (агрегат) | частично |
| `POST /v1/analytics/product-queries/details` | тексты запросов (до 15 на SKU) | частично |
| `POST /v2/analytics/stock_on_warehouses` | остатки по складам | да (метод будет отключён, оставлен как разрез) |
| `POST /v1/analytics/turnover/stocks` | IDC / ADS / turnover | да |
| `POST /v1/analytics/stocks` | ликвидность остатков | да |
| `POST /v1/product/rating-by-sku` | контент-рейтинг карточки | да |
| `POST /v1/rating/summary` | рейтинги продавца | да |
| `GET /v1/actions` | список акций | да |
| `POST /v1/actions/products` | товары в акции | да |

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

- `GET /api/client/campaign` — список кампаний (все типы).
- **Оплата за клик** (`advObjectType=SKU`): `POST /api/client/statistics/json` (пачки до 10 id, период до 62 дней) → poll `GET /api/client/statistics/{UUID}` → `GET /api/client/statistics/report?UUID=`. В отчёте строки по SKU: views, clicks, expense/moneySpent, orders, sales, toCart.
- **Оплата за заказ** (`SEARCH_PROMO` / единая кампания «все товары»): `POST /api/client/statistic/products/generate/json` (`from`/`to` RFC 3339). Если в списке нет SEARCH_PROMO или generate пустой — `GET /api/client/statistics/all_sku_promo/products/generate/json`.

`GET /api/client/statistics/campaign/product` **не используем** для join к товарам: это CSV/итог кампании без разреза по SKU.

`POST /api/client/statistics/products/sku` даёт SKU-строки, но `dateFrom` не раньше вчера — для периода snapshot не подходит (им пользуется A/B-тик).

Без ключей: `advertising_status = skipped_no_credentials`, snapshot всё равно `done`. После смены ключей нужно **пересобрать snapshot**, а не только перегенерировать ИИ.

## Snapshot `result_json`

```json
{
  "meta": {
    "generated_at": "...",
    "period": { "begin_date": "Y-m-d", "end_date": "Y-m-d" },
    "analytics_period": { "begin_date": "Y-m-d", "end_date": "Y-m-d" },
    "period_clamped": false,
    "sources_collected": ["products", "analytics", "product_queries", "stocks", "advertising", "content", "seller_rating", "promos"],
    "seller_rating": {},
    "actions": [],
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
      "price_indexes": { "color_index": "GREEN" },
      "analytics": { "revenue": 0, "ordered_units": 0, "period": {} },
      "search": { "unique_search_users": 0, "gmv": 0, "queries": [] },
      "stocks": { "free_to_sell": 0, "reserved": 0, "promised": 0 },
      "turnover": { "ads": 0, "current_stock": 0, "idc": 0 },
      "liquidity": { "turnover_grade": null, "days_without_sales": 0 },
      "content_rating": { "rating": null, "groups": [] },
      "promos": [],
      "advertising": { "views": 0, "clicks": 0, "spend": 0, "orders": 0 },
      "ads_vs_analytics": { "orders_gap": 0, "orders_ratio_ads_to_analytics": null }
    }
  ]
}
```

Join: analytics/ads/stocks по **SKU** → `products[].skus.all`.

- Статусы отчёта: `processing | done | failed`
- AI `data_sources`: `products`, `analytics`, `search`, `stocks`, `advertising`, `content`, `seller_rating`, `promos`
- `ads_vs_analytics` в LLM только если выбраны analytics **и** advertising
- `meta.seller_rating` / `meta.actions` в LLM только при выбранных `seller_rating` / `promos`
- Выбор источников — в админке `/cw-page/services/oz-ai-cabinet/prompts`
- Стоимость AI-генерации — динамическая, по токенам. Ставки общие с WB: `/cw-page/credit-pricing`, блок «ИИ-анализ кабинета». Поле шаблона `credits_cost` больше не используется для списания.
- При старте/перегенерации: оценка snapshot → `reserve` с запасом 1.3. Как только job получил ответ ИИ и поставил `done` — `settleOpenHold` на фактическую сумму + запись в `ai_cabinet_analyzer_credit_charges`. Failed job — `releaseOpenHold`. Snapshot не тарифицируется.
- На анализе сохраняются `provider`, `credits_charged`, `billing_snapshot`.
- AI: Gemini + GPT fallback, `AiTaskType::OZ_AI_CABINET_ANALYZER_AI`
- Запрос к ИИ всегда идёт в модель из `GEMINI_PRO_MODEL` (alias `gemini`). После ответа в анализе сохраняется фактический `modelVersion` (или GPT при fallback) — это для биллинга/лога, не для следующего запуска. Перегенерация снова запрашивает текущий Gemini, а не сохранённый id.

## Очереди

```bash
php artisan queue:work --queue=oz_ai_cabinet_analyzer --tries=3 --timeout=3600 --sleep=1
```

`QUEUE_RETRY_AFTER` должен быть **> 3600** (по умолчанию 3700), иначе database-очередь дублирует длинные job. Подробности: [queues.md](queues.md), [wb-ai-cabinet-analyzer.md](wb-ai-cabinet-analyzer.md) (раздел про дубли запросов к ИИ).

## Связанные документы

- [oz-cabinets.md](oz-cabinets.md)
- [wb-ai-cabinet-analyzer.md](wb-ai-cabinet-analyzer.md)
