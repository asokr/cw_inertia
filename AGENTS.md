# AGENTS.md

Инструкции для AI-агентов, работающих с этим репозиторием.

## О проекте

Laravel-приложение для подписчиков маркетплейсов **Wildberries** и **Ozon** (подписки, лимиты, инструменты продавца, платежи, блог, админка).

| Слой          | Технологии                                                                  |
| ------------- | --------------------------------------------------------------------------- |
| Backend       | PHP 8.3+, Laravel 13, MySQL, Composer                                       |
| Admin UI      | Vue 3, Inertia.js, Tailwind, shadcn-vue, TanStack Table, Vite (`/cw-page/`) |
| Subscriber UI | Vue 3, Inertia.js, Tailwind, shadcn-vue, Vite (`/panel/*`)                  |
| Очереди       | Laravel Queue (отдельные очереди на инструмент)                             |
| Баланс        | `O21\LaravelWallet`                                                         |
| Права         | Spatie Permission (`guard: web` для Inertia)                                |
| Тесты         | PHPUnit (`tests/Unit`, `tests/Feature`)                                     |

## Документация (`./docs`) — обязательно

Перед изменениями **сверяйся с документацией в `./docs`**. Там описаны архитектура, контракты, permissions, маршруты и поведение инструментов.

- Оглавление и обзор: [`docs/README.md`](docs/README.md)
- Инструменты и модули: файлы `docs/*.md` (WB/Ozon кабинеты, price calc, feedbacks, repricer, AI, blog и т.д.)
- Миграция Inertia: `docs/inertia-migration-*.md`

### Обновление документации

При **изменении или добавлении функционала** документацию нужно обновлять в том же изменении (или сразу после него, до завершения задачи):

1. Если затронут существующий модуль — обнови соответствующий файл в `docs/`.
2. Если добавлен новый инструмент/модуль — создай новый `docs/<name>.md` и добавь ссылку в таблицу инструментов в `docs/README.md`.
3. Обновляй: маршруты, permissions, модели/таблицы, jobs/очереди, Inertia-страницы, API-контракты polling, shared props, бизнес-правила.
4. **Очереди:** при добавлении, переименовании или смене очереди у job — обязательно обнови [`docs/queues.md`](docs/queues.md) (сводка, секция очереди, воркеры).
5. Не оставляй устаревшие описания «на потом».
6. какой либо текст на фронте сайта - пиши для пользователей - не нужно там использовать технические обороты, техническую информацию

## Язык

- **Комментарии в коде — на русском** (PHP, JS/Vue, SQL-миграции, тесты).
- **PHPDoc / JSDoc** — на русском, если пишешь описание.
- **Сообщения пользователю, flash, validation** — на русском (как в проекте).
- **Имена классов, методов, переменных, файлов** — на английском, в стиле Laravel/Vue.
- Документация в `./docs` — на русском.

## Архитектурные паттерны

### Backend

```
Controller (Web) → Service → Model / Job / External API
```

- **Админка:** `app/Http/Controllers/Web/Admin/*` → `app/Services/Admin/*` → `resources/js/Pages/Admin/*`
- **Подписчик:** `app/Http/Controllers/Web/Subscriber/*` → `app/Services/Subscriber/*` → `resources/js/Pages/Subscriber/*`
- Form Request'ы: `app/Http/Requests/`
- Jobs: `app/Jobs/`
- Ownership concerns: `app/Http/Controllers/Web/Subscriber/Concerns/*`

### Frontend (Inertia)

- Страницы: `resources/js/Pages/`
- Компоненты: `resources/js/components/`
- Layouts: `resources/js/Layouts/`
- Composables: `resources/js/composables/`
- Навигация: `resources/js/config/adminNav.js`, `resources/js/config/subscriberNav.js`
- UI-kit: `resources/js/components/ui/` (shadcn-vue стиль)

### Маршруты

| Файл                          | Назначение                          |
| ----------------------------- | ----------------------------------- |
| `routes/admin.php`            | Админка `/cw-page/*`                |
| `routes/subscriber.php`       | Панель `/panel/*`                   |
| `routes/subscriber-tools.php` | Инструменты подписчика              |
| `routes/blog.php`             | Блог                                |
| `routes/web.php`              | Публичные страницы, auth            |
| `routes/api.php`              | Legacy API, webhooks, auth-adjacent |

UI инструментов ходит в **Web/Inertia** (`/panel/*`), не в legacy `/subscriber/*` API.

### Кабинеты маркетплейсов

- **WB:** единая сущность `wb_cabinets`, активный — `users.selected_wb_cabinet_id` (см. `docs/wb-cabinets.md`).
- **Ozon:** `oz_cabinets` (см. `docs/oz-cabinets.md`).
- Workspace WB-инструментов — flat URL без `{cabinetId}` в path; кабинет из шапки.

### JSON polling (Inertia JSON endpoints)

```json
{
  "success": true,
  "messages": ["..."],
  "data": {}
}
```

## Правила работы агента

1. **Читай `./docs` перед правками** по затронутому модулю.
2. **Обновляй `./docs`** при изменении/добавлении функционала.
3. **Комментарии — на русском.**
4. Следуй существующим паттернам проекта: не вводи новый стиль архитектуры без необходимости.
5. Минимальный diff: не рефактори несвязанный код, не правь «заодно» форматирование чужих файлов.
6. Не коммить секреты (`.env`, ключи, токены).
7. Не удаляй и не «упрощай» бизнес-логику без явного запроса.
8. Permissions и роли — через Spatie; сидер: `database/seeders/Roles.php` (если есть/меняется — отрази в docs).
9. Очереди и внешние API (WB/Ozon/AI) — учитывай rate limits, jobs и идемпотентность. Справочник очередей: [`docs/queues.md`](docs/queues.md) — **обновляй при любом изменении/добавлении очереди или job**.
10. После существенных backend-изменений по возможности запускай релевантные тесты PHPUnit.

## Типичные команды

```bash
# Зависимости
composer install
npm install

# Dev frontend
npm run dev

# Production build
npm run build

# Миграции
php artisan migrate

# Тесты
php artisan test
# или
./vendor/bin/phpunit

# Очереди (локально)
php artisan queue:work
```

## Где что лежит

| Путь                        | Содержимое              |
| --------------------------- | ----------------------- |
| `app/Models/`               | Eloquent-модели         |
| `app/Services/`             | Бизнес-логика           |
| `app/Jobs/`                 | Фоновые задачи          |
| `app/Http/Controllers/Web/` | Inertia/web контроллеры |
| `app/Http/Requests/`        | Валидация               |
| `database/migrations/`      | Миграции                |
| `database/seeders/`         | Сидеры                  |
| `resources/js/Pages/`       | Inertia-страницы        |
| `resources/js/components/`  | Vue-компоненты          |
| `tests/`                    | PHPUnit                 |
| `docs/`                     | Проектная документация  |

## Чеклист перед завершением задачи

- [ ] Код соответствует паттернам Controller → Service → Job/Model
- [ ] Комментарии на русском
- [ ] Прочитаны релевантные файлы в `./docs`
- [ ] Документация в `./docs` обновлена (или создана), при необходимости обновлён `docs/README.md`
- [ ] Если менялись jobs/очереди — обновлён `docs/queues.md`
- [ ] Permissions / routes / nav согласованы (backend + frontend)
- [ ] Нет захардкоженных секретов
- [ ] При необходимости добавлены/обновлены тесты
