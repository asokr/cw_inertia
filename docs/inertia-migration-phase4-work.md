# Phase 4 — файл работ: уход от Api\Subscriber

Связанные документы: [inertia-migration-matrix.md](inertia-migration-matrix.md)

**Цель:** убрать legacy `Api\Subscriber` роуты и контроллеры; Web-контроллеры вызывают Services напрямую.

**Правило:** при завершении PR — отметить чекбокс, указать дату и краткий итог в блоке «Статус». Не переходить к следующему PR, пока не пройдены его критерии приёмки.

---

## Общий прогресс

| PR | Название | Статус |
|----|----------|--------|
| PR4.1 | Platform + webhooks | ✅ готов |
| PR4.2 | AI Marketplace | ✅ готов |
| PR4.3 | WB Feedbacks | ✅ готов |
| PR4.4 | Ozon Feedbacks | ✅ готов |
| PR4.5 | WB Price Calc V3 | ✅ готов |
| PR4.6 | Ozon Price Calc | ✅ готов |
| PR4.7 | WB Repricer | ✅ готов |
| PR4.8 | Profitability, AI Cabinet, Promo Calc | ✅ готов |
| PR4.9 | Final cleanup | ✅ готов |

Легенда: ⬜ не начат · 🔄 в работе · ✅ готов

---

## PR4.1 — Platform + webhooks

- [x] **Реализован**

**Задачи**

- Удалить из `routes/api.php`:
  - `GET /api/client/me` (Nuxt)
  - `subscriber/blog/*`
  - platform subscriber: profile, plans, subscriptions, extra-limits, payments history/deposit
- Перенести webhooks в Web-контроллеры (без auth), сохранить URL:
  - `POST /api/payments/yoo/callback` → YooKassa
  - `POST /api/services/wb-search/webhook` → WB Search
- Вынести логику callback из `Api\Subscriber\PaymentController` в Service
- Удалить `Api\Subscriber\User/*`, `Api\Subscriber\Blog/*`, `Api\Subscriber\PaymentController` (после переноса callback)
- `MarketplaceController::refreshLimits` — убрать зависимость от `Api\Subscriber\User\ProfileController`

**Критерии приёмки**

- Platform работает через `routes/subscriber.php` без API-дублей
- Webhooks отвечают на прежних URL
- Нет ссылок на удалённые platform/blog API-контроллеры

**Статус:** 2026-07-14 — удалены platform/blog/nuxt API routes; webhooks перенесены на `Web\Webhook\*` + Services; удалены `Api\Subscriber\User/*`, `Blog/*`, `PaymentController`; `MarketplaceController::refreshLimits` на `ToolLimits`.

---

## PR4.2 — AI Marketplace

- [x] **Реализован**

**Задачи**

- Рефакторинг [`MarketplaceController`](../app/Http/Controllers/Web/Subscriber/Ai/MarketplaceController.php) — прямые вызовы Services
- Services:
  - переиспользовать `AiImageService`, `AiImageGenerationService`, `AiVideoGenerationService`
  - создать `SubscriberAiMarketplaceService` (Gemini marketplace)
  - создать `SubscriberAiVideoService` (Grok start/status/reference)
- Удалить `app/Http/Controllers/Api/Subscriber/Ai/*` (6 файлов)
- Удалить AI routes из `routes/api.php`

**Критерии приёмки**

- `tests/Feature/Web/Subscriber/Ai/*` — green
- Polling JSON endpoints (`/panel/ai/image/generations`, `/panel/ai/video/...`) работают без API-прокси

**Статус:** 2026-07-14 — логика перенесена в `SubscriberAiMarketplaceService`, `SubscriberAiImageService`, `SubscriberAiVideoService`; generations через `AiImageGenerationService`/`AiVideoGenerationService`; удалены 5 API AI-контроллеров и routes; `AiController` оставлен для feedbacks (PR4.3).

---

## PR4.3 — WB Feedbacks

- [x] **Реализован**

**Задачи**

| API Controller | Service |
|----------------|---------|
| `FeedbacksController` | `WbFeedbacksService` |
| `FeedbacksClientsController` (AI/bot) | расширить `WbFeedbacksClientsService` |
| `FeedbacksTemplatesController` | `WbFeedbacksTemplatesService` |
| `FeedbacksStatController` | `WbFeedbacksStatsService` |

- Обновить Web: `FeedbacksController`, `TemplatesController`, `StatsController` (`ClientsController` уже эталон)
- Удалить соответствующие `Api\Subscriber\Wb\Feedbacks\*`

**Критерии приёмки**

- `tests/Feature/Web/Subscriber/Wb/WbFeedbacksTest.php` — green
- Нет `Api\Subscriber` в `Web/Subscriber/Wb/Feedbacks/*`

**Статус:** 2026-07-14 — Web-контроллеры на `WbFeedbacksService`, `WbFeedbacksClientsService`, `WbFeedbacksTemplatesService`, `WbFeedbacksStatsService`, `SubscriberAiTextService`; удалены 4 API WB Feedbacks контроллера и routes; `WbFeedbacksApiRemovalTest` + `WbFeedbacksTest` (10/11, guest redirect 302 — pre-existing).

---

## PR4.4 — Ozon Feedbacks

- [x] **Реализован**

**Задачи**

| API Controller | Service |
|----------------|---------|
| `Ozon\Feedbacks\FeedbacksClientsController` | `OzFeedbacksClientsService` |
| `Ozon\Feedbacks\FeedbacksController` | `OzFeedbacksService` |

- Обновить Web: `Oz/Feedbacks/ClientsController`, `Oz/Feedbacks/FeedbacksController`
- Удалить `Api\Subscriber\Ozon\Feedbacks\*`

**Критерии приёмки**

- `tests/Feature/Web/Subscriber/Oz/OzFeedbacksTest.php` — green

**Статус:** 2026-07-14 — `OzFeedbacksService`, `OzFeedbacksClientsService`; Web на Services + `SubscriberAiTextService`; удалены 2 API Oz Feedbacks контроллера, `AiController` (больше не нужен), routes; `OzFeedbacksApiRemovalTest` + `OzFeedbacksTest` (6/7, guest redirect 302 — pre-existing).

---

## PR4.5 — WB Price Calc V3

- [x] **Реализован**

**Задачи**

| API Controller | Service |
|----------------|---------|
| `PriceCalcCabinetsController` | `WbPriceCalcCabinetsService` |
| `PriceCalculationV3Controller` | расширить `WbPriceCalculationService` |

- Обновить Web: `Wb/PriceCalc/CabinetsController`, `Wb/PriceCalc/WorkspaceController`
- Удалить `Api\Subscriber\Wb\PriceCalculation\*`

**Критерии приёмки**

- `tests/Feature/Web/Subscriber/Wb/WbPriceCalcTest.php` — green

**Статус:** 2026-07-14 — `WbPriceCalcCabinetsService`, `WbPriceCalculationV3Service` (auth web); Web без `DelegatesToApiGuard`; PromoCalculator на `WbPriceCalcCabinetsService`; удалены 2 API контроллера и routes; `WbPriceCalcApiRemovalTest` + `WbPriceCalcTest` (7/8, guest redirect 302 — pre-existing).

---

## PR4.6 — Ozon Price Calc

- [x] **Реализован**

**Задачи**

| API Controller | Service |
|----------------|---------|
| `Ozon\PriceCalc\CabinetsController` | `OzPriceCalcCabinetsService` |
| `FboController` + `FbsController` | `OzPriceCalcWorkspaceService` |

- Обновить Web: `Oz/PriceCalc/CabinetsController`, `Oz/PriceCalc/WorkspaceController`
- Удалить `Api\Subscriber\Ozon\PriceCalc\*`

**Критерии приёмки**

- `tests/Feature/Web/Subscriber/Oz/OzPriceCalcTest.php` — green

**Статус:** 2026-07-14 — `OzPriceCalcCabinetsService`, `OzPriceCalcFboService`, `OzPriceCalcFbsService`; Web без `DelegatesToApiGuard`; удалены 3 API контроллера и routes; `OzPriceCalcApiRemovalTest` + `OzPriceCalcTest` (13/14, guest redirect 302 — pre-existing).

---

## PR4.7 — WB Repricer

- [x] **Реализован**

**Задачи**

| API Controller | Service |
|----------------|---------|
| `RepricerCabinetsController` | `RepricerCabinetsService` |
| `RepricerSettingsController` | `RepricerTimeSettingsService` |
| `RepricerStocksController` | `RepricerStocksService` |
| `RepricerCompetitorsController` | `RepricerCompetitorsService` + webhook |

- Обновить Web: `Repricer/CabinetsController`, `TimeSettingsController`, `StocksController`
- Competitors: CRUD API удалить (Inertia UI нет); webhook перенести в PR4.1 или здесь
- Тест `RepricerCompetitorsControllerTest` → service/webhook tests

**Критерии приёмки**

- `tests/Feature/Web/Subscriber/Wb/WbRepricerTest.php` — green

**Статус:** 2026-07-14 — `RepricerCabinetsService`, `RepricerTimeSettingsService`, `RepricerStocksService`, `RepricerCompetitorsService`; Web + PromoCalculator на Services; удалены 4 API Repricer контроллера и routes; webhook конкурентов — `WbSearchWebhookController` (PR4.1); `RepricerCompetitorsControllerTest` удалён → `WbRepricerApiRemovalTest`; `WbRepricerTest` (8/9, guest redirect 302 — pre-existing).

---

## PR4.8 — Profitability, AI Cabinet Analyzer, Promo Calculator

- [x] **Реализован**

**Задачи**

| API Controller | Service |
|----------------|---------|
| `ProfitabilityCabinetsController` + `ProfitabilityController` | `ProfitabilityApiService` + `WbProfitabilityCabinetsService` |
| `AiCabinetAnalyzerCabinetsController` + Reports + AiAnalyses | `AiCabinetAnalyzerService` + существующие |
| `PromoCalculatorController` | `WbPromoCalculatorService` |

- Обновить соответствующие Web-контроллеры в `Wb/Profitability/*`, `Wb/AiCabinetAnalyzer/*`, `Wb/PromoCalculator/*`
- Удалить соответствующие `Api\Subscriber\Wb\*`

**Критерии приёмки**

- `WbProfitabilityTest`, `WbAiCabinetAnalyzerTest`, `WbPromoCalculatorTest` — green

**Статус:** 2026-07-14 — `WbProfitabilityCabinetsService`, `WbProfitabilityReportService`, `WbAiCabinetAnalyzerCabinetsService`, `WbAiCabinetAnalyzerReportsService`, `WbAiCabinetAnalyzerAiAnalysesService`, `WbPromoCalculatorService`; Web на Services; удалены 6 API контроллеров и routes; `WbProfitabilityTest` green (16/16), `WbAiCabinetAnalyzerTest` (10/11), `WbPromoCalculatorTest` (9/10), ApiRemoval tests green.

---

## PR4.9 — Final cleanup

- [x] **Реализован**

**Задачи**

- Вычистить `routes/api.php` от всех `Api\Subscriber` imports и subscriber routes
- Удалить `app/Http/Controllers/Api/Subscriber/` целиком (если не осталось ссылок)
- Удалить `DelegatesToApiGuard`; упростить `HandlesApiResponses`
- Обновить тесты: `/api/subscriber/ai/media/` → `/panel/ai/media/` в fixtures
- Обновить `docs/inertia-migration-matrix.md`: Phase 4 → done
- Обновить `docs/README.md`: убрать Nuxt + REST API для подписчиков

**Критерии приёмки (финальные)**

```bash
rg "Api\\\\Subscriber" app/Http/Controllers/Web          # 0 matches
rg "subscriber/wb|subscriber/oz|subscriber/ai|subscriber/user" routes/api.php  # 0 (кроме webhooks)
php artisan test tests/Feature/Web/Subscriber             # green
```

**Статус:** 2026-07-14 — удалены `Api\Subscriber/*` (Coupon, Fullfilment, Wallet); fullfilment → `Web\FullfilmentController`; check-coupon только на `/check-coupon` (Web); удалён `DelegatesToApiGuard`; упрощён `HandlesApiResponses`; fixtures тестов на `/panel/ai/media/`; `SubscriberApiFinalCleanupTest`; docs обновлены.

---

## Порядок выполнения (строго по очереди)

```
PR4.1 → PR4.2 → PR4.3 → PR4.4 → PR4.5 → PR4.6 → PR4.7 → PR4.8 → PR4.9
```

PR4.2 и PR4.3 лучше делать подряд (общий `AiController` для feedbacks).