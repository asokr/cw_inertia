# Документация проекта subscribers_backend

Laravel API для подписчиков маркетплейсов Wildberries и Ozon. Админка — Inertia + Vue 3 на `/cw-page/`; основной фронт подписчиков — отдельное Nuxt-приложение, работающее через REST API.

## Стек

| Слой | Технологии |
|------|------------|
| Backend | Laravel 13, MySQL, Composer |
| Admin frontend | Vue 3, Inertia.js, Tailwind, shadcn-vue, TanStack Table, Vite |
| Subscriber frontend | Nuxt (внешний репозиторий) |
| Очереди | Laravel Queue (отдельные очереди на инструмент) |
| Баланс | `O21\LaravelWallet` |
| Права | Spatie Permission (`guard: web` для Inertia, `guard: api` для Nuxt) |

## Архитектура

```mermaid
flowchart TB
    subgraph clients [Clients]
        Nuxt[NuxtSubscriberFrontend]
        InertiaAdmin[InertiaAdminCwPage]
    end
    subgraph api [Laravel]
        WebRoutes[WebRoutes_cw-page]
        ApiRoutes[LegacyAPI_Nuxt]
        Instruments[InstrumentControllers]
        Jobs[QueueJobs]
    end
    subgraph external [External]
        WB[WildberriesAPI]
        Ozon[OzonAPI]
        AI[GeminiGrokOpenAI]
    end
    Nuxt --> ApiRoutes
    InertiaAdmin --> WebRoutes
    ApiRoutes --> Instruments
    WebRoutes --> Instruments
    Instruments --> Jobs
    Jobs --> WB
    Jobs --> Ozon
    Instruments --> AI
```

## Админка (`/cw-page/`)

Доступ по web-сессии (`auth`, `verified`):

| Роль / permission | Доступ |
|-------------------|--------|
| `super-admin` / `Супер-Админ` | Подписчики, планы, купоны, роли, сервисы, WB API stats |
| `blog.view` (+ create/update/delete) | Блог: посты, категории, теги |

Паттерн: `app/Http/Controllers/Web/Admin/*` → `app/Services/Admin/*` → `resources/js/Pages/Admin/*`.

Ключевые маршруты:

- `/cw-page/subscribers`, `/cw-page/plans`, `/cw-page/coupons` — управление подписчиками
- `/cw-page/services/feedbacks/*` — отзывы WB
- `/cw-page/services/repricer/*` — репрайсер
- `/cw-page/services/ai-cabinet/*` — ИИ-анализ кабинета
- `/cw-page/services/ai/*` — логи ИИ, архив расходов
- `/cw-page/wb/api-usage` — статистика WB API + drill-down по Seller ID

Legacy Vue SPA (`resources/js/views/dashboard/admin/`) удалён — админка полностью на Inertia.

## Формат API (Nuxt)

Все ответы инструментов следуют единому контракту:

```json
{
  "success": true,
  "messages": ["..."],
  "data": {}
}
```

Авторизация подписчиков: `auth:api`, `verified`, `role:Подписчик` + permission на конкретный инструмент.

## Инструменты

| Инструмент | Маркетплейс | Permission | Документация |
|------------|-------------|------------|--------------|
| AI Cabinet Analyzer | WB | `subscriber wb ai cabinet analyzer` | [wb-ai-cabinet-analyzer.md](wb-ai-cabinet-analyzer.md) |
| AI Marketplace | WB/Ozon | `subscriber ai` | [ai-marketplace.md](ai-marketplace.md) |
| Рентабельность | WB | `subscriber wb profitability` | [wb-profitability.md](wb-profitability.md) |
| Ценообразование V3 | WB | `subscriber wb price calculator` | [wb-price-calculation-v3.md](wb-price-calculation-v3.md) |
| Калькулятор акций | WB | `subscriber wb promo calculator` | [wb-promo-calculator.md](wb-promo-calculator.md) |
| Отзывы | WB | `subscriber wb feedbacks` | [wb-feedbacks.md](wb-feedbacks.md) |
| Отзывы | Ozon | `subscriber oz feedbacks` | [ozon-feedbacks.md](ozon-feedbacks.md) |
| Репрайсер | WB | `subscriber wb repricer` | [wb-repricer.md](wb-repricer.md) |
| Ценообразование | Ozon | `subscriber oz price calc` | [ozon-price-calculation.md](ozon-price-calculation.md) |
| Блог | — | `blog.view/create/update/delete` | [blog.md](blog.md) |
| Фулфилмент (цены) | — | `manager fullfilment` | [fullfilment.md](fullfilment.md) |

## Справочники

| Документ | Описание |
|----------|----------|
| [wb-ai-cabinet-analyzer-sales-funnel-fields.md](wb-ai-cabinet-analyzer-sales-funnel-fields.md) | Маппинг полей WB Sales Funnel |
| [ozon-price-calculation-frontend-columns.md](ozon-price-calculation-frontend-columns.md) | Колонки таблиц Ozon Price Calc для фронта |

## Платформенные модули (без отдельной документации)

- **Подписки и лимиты** — `SubscribersSubscriptions`, `limits_plan`, `limits_month`, `extra_limits_*` (JSON)
- **Платежи** — YooKassa (`/payments/yoo/*`)
- **Баланс** — пополнение/списание через wallet, лог `balance`
- **Админка подписчиков** — управление планами, купонами, ролями (Super-Admin)

## Ключевые файлы проекта

- Web-маршруты админки: [`routes/admin.php`](../routes/admin.php)
- Legacy API (Nuxt): [`routes/api.php`](../routes/api.php)
- Permissions: [`database/seeders/Roles.php`](../database/seeders/Roles.php)
- Inertia-страницы админки: [`resources/js/Pages/Admin/`](../resources/js/Pages/Admin/)
- Навигация админки: [`resources/js/config/adminNav.js`](../resources/js/config/adminNav.js)