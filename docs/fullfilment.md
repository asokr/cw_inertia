# Фулфилмент (цены)

## Права доступа

- Публичное чтение: без авторизации
- Управление ценами: `permission:manager fullfilment`
- Middleware admin: `auth:api`, `verified`

## Назначение

Публичный каталог цен на услуги фулфилмента и административное управление прайс-листом (города, склады, маркетплейсы, услуги).

## Ключевые файлы

- `app/Http/Controllers/Api/Subscriber/FullfilmentController.php`
- `app/Http/Controllers/Api/FullfilmentSettingsController.php`
- `app/Models/FullfilmentPrices.php`

## API эндпоинты

### Публичный (без auth)

- `GET /fullfilment` — список цен

Ответ содержит записи с полями: `city`, `warehouses`, `marketplaces`, `our_services`, `services` (JSON-структуры).

### Admin (manager fullfilment)

Resource `/admin/fullfilment` (кроме store, destroy):

- `GET` — список
- `GET /{id}` — одна запись
- `PUT/PATCH /{id}` — обновление

## Модели и БД

- Таблица: `fullfilment_prices`
- JSON-поля для гибкой структуры тарифов по городам и маркетплейсам

## Технические детали

- Не является подписным инструментом — отдельный permission для менеджеров фулфилмента
- Публичный endpoint используется лендингом/Nuxt без токена