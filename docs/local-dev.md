# Локальная разработка

Одна команда поднимает PHP-сервер, Vite, очередь и планировщик. OSPanel нужен только как MySQL.

## Запуск

Из корня проекта:

```bash
node dev.js
```

Скрипт стартует:

| Процесс | Команда | Зачем |
|---------|---------|--------|
| PHP | `php artisan serve` (`http://127.0.0.1:8000`) | HTTP бэкенда |
| Vite | `npm run dev` (порт **3001**) | HMR фронта Inertia/Vue |
| Очередь | `php artisan queue:listen` по всем именованным очередям | jobs инструментов |
| Планировщик | `php artisan schedule:work` | cron каждую минуту |

Остановка — `Ctrl+C` (гасятся все дочерние процессы).

Перед первым запуском:

```bash
composer install
npm install
```

Нужны `.env`, PHP 8.3+ в PATH (или OSPanel `C:\OSPanel\modules\PHP-8.3\PHP\php.exe`) и запущенный MySQL в OSPanel.

`APP_URL` в `.env` должен совпадать с адресом `artisan serve` (по умолчанию `http://127.0.0.1:8000`).

## Опции

```bash
node dev.js --no-serve         # без artisan serve
node dev.js --workers=split    # отдельный воркер на каждую очередь
node dev.js --no-queue
node dev.js --no-schedule
node dev.js --no-vite
node dev.js --help
```

`queue:listen` (не `queue:work`) — чтобы PHP-изменения подхватывались без перезапуска воркера.

Список очередей и таймауты: [queues.md](queues.md).

## Только фронт

Как раньше:

```bash
npm run dev
```

## PHP не в PATH

Задайте полный путь:

```bash
set PHP_BINARY=C:\OSPanel\modules\PHP-8.3\PHP\php.exe
node dev.js
```

Скрипт сам ищет `php` в PATH, затем типичные модули OSPanel `PHP-8.3+`.
