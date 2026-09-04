# Продакшен: после загрузки файлов

Скрипт [`scripts/prod-reload.sh`](../scripts/prod-reload.sh) — шаг после выкладки кода на сервер: очищает кеш Laravel, заново собирает production-кеш и мягко перезапускает **все** воркеры очередей.

Миграции, `composer install` и `npm run build` в скрипт не входят — их нужно сделать **до** вызова, если в выкладке есть новые зависимости, фронт или схема БД.

## Запуск

На сервере, из корня проекта, тем же пользователем, от которого крутятся PHP и воркеры:

```bash
bash scripts/prod-reload.sh
```

Или:

```bash
chmod +x scripts/prod-reload.sh
./scripts/prod-reload.sh
```

Нестандартный PHP:

```bash
PHP_BIN=/usr/bin/php8.3 bash scripts/prod-reload.sh
```

Reload php-fpm (чтобы сбросить OPcache после загрузки `.php`):

```bash
PHP_FPM_SERVICE=php8.3-fpm bash scripts/prod-reload.sh
```

Если `PHP_FPM_SERVICE` не задан, скрипт напечатает команду для ручного `systemctl reload`.

## Что делает скрипт

Порядок шагов важен: сигнал `queue:restart` пишется в application cache, поэтому его нельзя давать **до** очистки кеша.

1. `php artisan optimize:clear` — config, route, view, events, application cache, compiled.
2. `php artisan permission:cache-reset` — кеш ролей/прав Spatie.
3. Пересборка: `config:cache`, `event:cache`, `view:cache`. `route:cache` вызывается, но может быть пропущен: в маршрутах есть замыкания, Laravel их не кеширует.
4. `php artisan queue:restart` — все `queue:work` этого приложения (любая очередь) доработают текущий job и выйдут. Supervisor / systemd должен поднять процесс заново.
5. Опционально `systemctl reload` php-fpm.

Имена очередей и рекомендуемые воркеры: [queues.md](queues.md).

## Что скрипт не делает

| Шаг | Когда нужен |
|-----|-------------|
| `composer install --no-dev --optimize-autoloader` | менялись `composer.json` / `composer.lock` |
| `npm ci && npm run build` | менялся фронт (Vue/Inertia) |
| `php artisan migrate --force` | есть новые миграции |
| `php artisan storage:link` | первый деплой |

## Воркеры

`queue:restart` **не** стартует воркеры с нуля. Если процессы не под Supervisor / systemd с автоперезапуском, после сигнала они просто остановятся.

Рекомендуемый набор `queue:work` — в [queues.md](queues.md).
