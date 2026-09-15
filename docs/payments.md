# Платежи (ЮKassa)

Пополнение рублёвого баланса в кабинете идёт через ЮKassa. Других платёжных систем нет.

## Маршруты

| Метод | URL | Назначение |
|-------|-----|------------|
| `POST /panel/payments/deposit` | Кабинет, сессия | Создать платёж и редирект на оплату |
| `GET /panel/user/history` | Кабинет, сессия | История платежей |
| `POST /api/payments/yoo/callback` | Без auth | HTTP-уведомления ЮKassa |

URL колбека на проде: `https://cwplatform.ru/api/payments/yoo/callback` (https, без слэша на конце).

С маршрута колбека снят `throttle:api`.

## События в кабинете ЮKassa

Один URL на все события. Отмечать только:

| Событие | Нужно | Что делаем |
|---------|-------|------------|
| `payment.succeeded` | Да | Статус `CONFIRMED`, зачисление на баланс, при `plan_id` — смена тарифа |
| `payment.canceled` | Да | Статус `CANCELED`, баланс не трогаем |
| `payment.waiting_for_capture` | Нет | Платежи создаём с `capture: true`. Событие игнорируем |
| `payment_method.active` | Нет | Игнорируем |
| `refund.succeeded` | Нет | Игнорируем |

## Обработчик

`YooKassaWebhookController` → `SubscriberPaymentService::handleYooKassaCallback`.

Тело читается как JSON (`$request->getContent()`), без строгих конструкторов SDK: неполный `payment_method` или числовая metadata не ломают зачисление.

Поиск транзакции: `object.metadata.transaction_id`, иначе `payments_transactions.system_id = object.id`.

Повтор `payment.succeeded` по уже `CONFIRMED` платежу повторно баланс не начисляет.

Ответ 204 — уведомление принято (в том числе игнор неизвестного события и «транзакция не найдена»). Ошибка зачисления пробрасывается (не 204), чтобы ЮKassa повторила доставку.

## Создание платежа

`PaymentService::createPayment` отдаёт `url` и `id`. `id` сразу пишется в `system_id`. Metadata (`transaction_id`, `user_id`, `plan_id`) уходит строками.

## Логи

Работа колбека в лог не пишется.

После успешного зачисления — канал `balance` (daily, `storage/logs/balance-YYYY-MM-DD.log`): `YooKassa deposit completed` с `user_id`, `transaction_id`, `payment_id`, `amount`, `balance_before`, `balance_after`. По этим полям восстанавливается хронология пополнения.

## История в кабинете

`/panel/user/history`. Дата приходит с бэкенда как `d.m.Y H:i`. Статусы на экране по-русски (`CREATE`/`CREATED` → «Создан», `CONFIRMED` → «Подтверждён», `FAILED` → «Неудачный», `CANCELED` → «Отменён», `RETURNED` → «Возврат»). Колонку платёжной системы не показываем.

## Застрявшие CREATE

Если ЮKassa уже получила 204 на уведомление, она его не пришлёт снова. Такие платежи нужно закрыть вручную: в кабинете ЮKassa убедиться, что оплата `succeeded`, и пополнить баланс в админке (`/cw-page/subscribers/{id}` → «Пополнить»).
