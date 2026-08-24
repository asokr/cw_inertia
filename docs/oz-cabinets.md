# Единый кабинет Ozon

## Назначение

Инструменты Ozon работают от **одного общего кабинета** продавца (как Wildberries).

1. Пользователь создаёт **кабинет Ozon** один раз: название + Client ID + API Key (Seller API).
2. Опционально указывает **Performance API** Client ID + Client Secret (реклама; отдельная вкладка в ЛК Ozon, не роль Seller-ключа).
3. В шапке панели выбирает **активный кабинет** (`users.selected_oz_cabinet_id`).
4. Инструменты Ozon (ценообразование, ИИ анализ кабинета) читают данные для этого кабинета.
5. Данные инструментов ссылаются на `oz_cabinets.id` через `cabinet_id`.

Миграции со старых tool-кабинетов **нет**: legacy-данные price-calc очищаются при деплое, кабинеты создаются заново.

## UX

| Элемент | Поведение |
|---------|-----------|
| Переключатель в шапке | `MarketplaceCabinetSwitcher.vue` — секции Wildberries + Ozon в одном dropdown |
| Смена активного кабинета Ozon | POST `/panel/oz/cabinets/select` → редирект на `/panel` |
| Нет кабинета | Инструменты показывают `Subscriber/Oz/Shared/NoCabinet` |

Shared props Inertia (только для `/panel/*`):

- `oz_cabinets` — `[{ id, name, client_id, performance_client_id, has_performance_credentials }, ...]`
- `selected_oz_cabinet` — то же для активного

## Web routes

Prefix: `/panel/oz/cabinets` · name: `subscriber.oz.cabinets.*`

| Method | URL | Named route | Назначение |
|--------|-----|-------------|------------|
| GET | `/` | `…index` | Redirect → `/panel` |
| POST | `/` | `…store` | Создать кабинет |
| PUT | `/{cabinet}` | `…update` | Обновить имя / client_id / ключ |
| DELETE | `/{cabinet}` | `…destroy` | Удалить кабинет (+ FBO/FBS rows) |
| POST | `/select` | `…select` | Выбрать активный (`cabinet_id`) |

## Ключевые файлы

- `app/Models/Subscribers/Oz/OzCabinet.php` — таблица `oz_cabinets`
- `app/Services/Subscriber/Oz/OzCabinetService.php` — CRUD, select, лимиты
- `app/Support/Oz/SelectedOzCabinet.php`
- `app/Http/Controllers/Web/Subscriber/Oz/Cabinets/CabinetsController.php`
- `app/Http/Controllers/Web/Subscriber/Concerns/ResolvesSelectedOzCabinet.php`
- `resources/js/components/subscriber/MarketplaceCabinetSwitcher.vue`
- `resources/js/Pages/Subscriber/Oz/Shared/NoCabinet.vue`

## Схема данных

### `oz_cabinets`

| Поле | Описание |
|------|----------|
| `id` | PK — этот id используют tool-таблицы как `cabinet_id` |
| `user_id` | Владелец |
| `name` | Отображаемое имя |
| `client_id` | Ozon Client ID Seller API (unique per user) |
| `apikey` | Зашифрованный Seller API-ключ (`EncryptCast`) |
| `performance_client_id` | Опционально: Client ID Performance API (реклама) |
| `performance_client_secret` | Опционально: Client Secret Performance API (`EncryptCast`) |
| `last_sync_error` | Ошибка последней синхронизации price-calc |

### `users.selected_oz_cabinet_id`

Активный кабинет Ozon. При удалении выбранного — переключается на другой (или `null`).

## Как инструменты резолвят кабинет

Trait `ResolvesSelectedOzCabinet`:

```php
$cabinet = $this->requireSelectedOzCabinet($request, 'Название инструмента');
// OzCabinet | Inertia NoCabinet page
```

Jobs: `OzCabinet::find($cabinetId)`.

## Лимиты

- План-лимит: **только** `limits_plan.oz_cabinets` (лейбл: «Единый кабинет Ozon»).
- Проверка и списание — **только** при создании кабинета (`OzCabinetService` / `POST /panel/oz/cabinets`).
- Инструменты (ценообразование, ИИ-анализ) кабинетный plan-лимит **не** проверяют.

## Flat workspace

```
/panel/oz/price-calc
```

Legacy path `/panel/oz/price-calc/cabinets/{cabinet}` → redirect на flat URL.

## Связанные документы

- [wb-cabinets.md](wb-cabinets.md) — образец архитектуры
- [oz-ai-cabinet-analyzer.md](oz-ai-cabinet-analyzer.md) — AI Анализ кабинета Ozon
- [ozon-price-calculation.md](ozon-price-calculation.md)
- [oz-ab-testing.md](oz-ab-testing.md) — A/B-тест фото; нужны ключи Performance API
- [inertia-migration-matrix.md](inertia-migration-matrix.md)
