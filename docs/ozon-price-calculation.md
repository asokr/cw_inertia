# Ценообразование Ozon

## Права доступа

- Permission: `subscriber oz price calc`
- Middleware: `auth:api`, `verified`, `role:Подписчик`

## Связанные документы

- [ozon-price-calculation-frontend-columns.md](ozon-price-calculation-frontend-columns.md)

## 1. Общее описание

Инструмент для расчета рентабельности, логистики и итоговых цен (юнит-экономика) для маркетплейса Ozon. Поддерживает две основные схемы продажи: **FBO** (со склада Ozon) и **FBS** (со склада продавца).

Позволяет синхронизировать карточки товара напрямую из кабинета (по API), загружать/выгружать параметры через Excel-файлы и автоматически рассчитывать стоимость логистики и рекомендуемые цены (стоп-цена, минимальная цена, текущая цена).

Приложение разделяет работу с таблицами FBO и FBS, однако использует схожие процессы фоновых задач и структуру.

---

## 2. Структура БД и Модели

Все модели лежат в `app/Models/Subscribers/Oz/PriceCalc/`:

1. **`OzPriceCalcCabinet`**
    - Таблица: `oz_price_calc_cabinets`
    - Хранит данные для доступа к Ozon API: `name`, `client_id`, `apikey` (хранится в зашифрованном виде через `EncryptCast`).
    - Имеет связи `fboRecords()` и `fbsRecords()` ко всем загруженным карточкам в рамках кабинета.
2. **`OzPriceCalcFbo`**
    - Таблица: `oz_price_calc_fbo`
    - Хранит данные карточек пользователя для схемы FBO: артикул (`ozon_article`), баркод, габариты (`weight_kg`, `length_cm`, `width_cm`, `height_cm`, `volume_liters`).
    - **Поля ручного ввода (импорт/интерфейс):** себестоимость (`cost_price`), желаемая маржа (`margin_percent`), расходы на фулфилмент (`fulfillment_fee`), процент доп. расходов (`dop_rashod_percent`), процент невыкупа (`buyout_percent`), налог (`tax_percent`), комиссия (`commission_percent`), расходы на рекламу (`advertising_percent`), доля расходов на продвижение (`promotion_percent`), наценки логистики (`logistics_markup_percent`, `price_markup_for_logistics_percent`).
    - **Расчетные поля:** логистика FBO (`logistics_fbo`, `logistics_fbo_over_190`, `acceptance_fbo`), а также итоговые цены (`stop_price`, `min_price`, `current_price`).
3. **`OzPriceCalcFbs`**
    - Таблица: `oz_price_calc_fbs`
    - Аналогична FBO, но имеет только свои расчетные поля для FBS (`logistics_fbs`, `logistics_fbs_over_190`). Отсутствуют поля наценок логистики и стоимость приемки.

---

## 3. Контроллеры и Маршруты

Все роуты обернуты middleware `permission:subscriber oz price calc`. Контроллеры лежат в `app/Http/Controllers/Api/Subscriber/Ozon/PriceCalc/`.

### 3.1. `CabinetsController`

- `GET /subscriber/oz/price-calc/cabinets` — Список кабинетов пользователя.
- `POST /subscriber/oz/price-calc/cabinets` — Создание кабинета.
- `PUT/PATCH /subscriber/oz/price-calc/cabinets/{id}` — Обновление кабинета (доступы API).
- `DELETE /subscriber/oz/price-calc/cabinets/{id}` — Удаление кабинета.

### 3.2. `FboController` (схема FBO)

- `GET /subscriber/oz/price-calc/cabinets/{cabinetId}/fbo` — Вывод карточек FBO с пагинацией и поиском.
- `GET /subscriber/oz/price-calc/cabinets/{cabinetId}/fbo/status` — Получение статуса фоновой выгрузки/расчета.
- `POST /subscriber/oz/price-calc/cabinets/{cabinetId}/sync` — Запуск `SyncPriceCalcJob` по FBO.
- `POST /subscriber/oz/price-calc/cabinets/{cabinetId}/calculate` — Запуск `CalculatePriceJob` по FBO.
- Эндпоинты импорта/экспорта: `import`, `import-status`, `export`, `export-status`.

### 3.3. `FbsController` (схема FBS)

- Префикс `{cabinetId}/fbs/...`
- Абсолютно идентичные FboController эндпоинты, только запускающие Jobs с параметром `type = 'fbs'`.

---

## 4. Фоновые процессы (Jobs)

Лежат в `app/Jobs/Ozon/`:

1. **`SyncPriceCalcJob($cabinetId, $type)`**
    - Обращается к `OzonApiService` для загрузки товаров кабинета (пагинация API Ozon через `last_id`).
    - Извлекает габариты и вес (переводя их в кг и см, если Ozon API отдало в фунтах/граммах или миллиметрах).
    - Записывает/Обновляет данные в таблицах `oz_price_calc_fbo` или `oz_price_calc_fbs` через `updateOrCreate` по ключу `[cabinet_id, ozon_article, barcode]`.
    - Удаляет из БД карточки, которые исчезли на Ozon (`delete()`).
2. **`ExportPriceCalcJob($cabinetId, $userId, $type)`**
    - Формирует Excel-шаблон (`app/Exports/Ozon/PriceCalc/FboFbsExport.php`).
    - Сохраняет файл в публичную папку и устанавливает статус готовности в Кеш.
3. **`ImportPriceCalcJob($cabinetId, $userId, $filePath, $type)`**
    - Парсит Excel, обновляя ручные поля в БД. Отбрасывает невалидные строки.
    - После успешного импорта автоматически запускает калькуляцию (`CalculatePriceJob`) для того же режима (`fbo`/`fbs`).
4. **`CalculatePriceJob($cabinetId, $type)`**
    - Производит пересчет всех расчетных столбцов на основе габаритов и пользовательских процентов.
    - Разбивает данные по 500 строк через `chunkById` для экономии памяти.

---

## 5. Вычислительная логика (`CalculatePriceJob`)

### Для схемы FBS

1. **Объем (`volume_liters`)** = `(Д * Ш * В) / 1000`.
2. **Базовая логистика FBS:**
    - Если до 4 л: `((Объем - 1) * 10.17) + 46.77`.
    - Если до 190 л: `((Объем - 3) * 15.25) + 67.11`.
    - Свыше - отдельные коэффициенты.
3. **Логистика с учетом невыкупа (`logistics_fbs`)**:
   Рассчитывается затрата на прямую логистику (отправка 100%) + обратная логистика для позиций, которые не выкупили (100% - `buyout_percent`).
4. **Формирование цен:**
    - **`stop_price`**: `= (Себестоимость * (1 + Маржа) + Фулфилмент) / (1 - Доп.расходы)`.
    - **`min_price`**: Базируется на `stop_price` с добавлением общей стоимости логистики FBS + 55 руб., разделенного на сумму комиссий и налогов.
    - **`current_price`**: `= min_price / (1 - ПроцентРекламы)`.

### Для схемы FBO

1. **Базовая логистика FBO** схожа с FBS (лимиты 4 л и 190 л), но имеет повышающий коэффициент `logistics_markup_percent` (наценка за логистику).
2. **Стоимость приемки (`acceptance_fbo`)** = `5 руб. + (Объем - 1)`.
3. **Формирование цен**:
    - `stop_price` рассчитывается так же, как в FBS.
    - Во время расчета `min_price` используются свои константы (45 руб вместо 55 руб для FBS), применяется стоимость приемки, дополнительно учитывается процент `price_markup_for_logistics_percent`.

---

## 6. Актуализация April 2026

### 6.1. Формат Excel

- Экспорт и импорт переведены на многострочную шапку:
    - строка 1: пустая служебная строка;
    - строка 2: названия колонок;
    - строка 3: единицы измерения;
    - строка 4: признак `заполняется` / `рассчёт`;
    - данные начинаются с 5-й строки.
- Для FBO и FBS используются отдельные файлы (`fbo.xlsx`, `fbs.xlsx`).
- Импорт обрабатывает только поля `заполняется`.

### 6.2. Изменения FBO

- Удалена из бизнес-потока надбавка к логистике (`logistics_markup_percent`):
    - поле не используется в формулах расчета;
    - поле не участвует в импорте/экспорте.
- Добавлено новое поле `dopakovka_rub` (доупаковка) для ручного ввода.
- `min_price` рассчитывается с учетом:
    - `logistics_fbo_over_190` (логистика с учетом выкупа),
    - `acceptance_fbo`,
    - `dopakovka_rub`,
    - фиксированной константы `+45`,
    - процентов: реклама, комиссия, налог, надбавка к цене за логистику, `1.5%`.

### 6.3. Изменения FBS

- Удалены из Excel-контракта поля приемки и логистических надбавок.
- `min_price` рассчитывается по формуле с константой `+65`.

### 6.4. Логистика (FBO/FBS)

- Базовая логистика рассчитывается по ступенчатой таблице тарифов от объема (л).
- `logistics_*_over_190` теперь используется как поле `логистика с учетом выкупа`:
    - `round((logistics * 100 + (100 - buyout_percent) * logistics) / buyout_percent)`.

### 6.5. Округления (синхронизированы с Excel)

- `ОКРУГЛВВЕРХ` => `ceil`.
- `ОКРУГЛ` => `round`.

---

## 7. Маппинг колонок для фронта

См. отдельный справочник: [ozon-price-calculation-frontend-columns.md](ozon-price-calculation-frontend-columns.md)
