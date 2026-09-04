# Ценообразование Ozon

## Права доступа

- Permission: `subscriber oz price calc`
- Кабинет: **единый Ozon** (`oz_cabinets` / `selected_oz_cabinet_id`) — см. [oz-cabinets.md](oz-cabinets.md)

## Связанные документы

- [oz-cabinets.md](oz-cabinets.md)
- [ozon-price-calculation-frontend-columns.md](ozon-price-calculation-frontend-columns.md)

## 1. Общее описание

Инструмент для расчета рентабельности, логистики и итоговых цен (юнит-экономика) для маркетплейса Ozon. Поддерживает две основные схемы продажи: **FBO** (со склада Ozon) и **FBS** (со склада продавца).

Flat workspace: `/panel/oz/price-calc` — кабинет берётся из шапки, не из URL.

---

## 2. Структура БД и Модели

1. **`OzCabinet`** (`app/Models/Subscribers/Oz/OzCabinet.php`)
    - Таблица: `oz_cabinets`
    - Доступ к Ozon API: `name`, `client_id`, `apikey` (`EncryptCast`), `last_sync_error`
2. **`OzPriceCalcFbo`** / **`OzPriceCalcFbs`**
    - Таблицы: `oz_price_calc_fbo`, `oz_price_calc_fbs`
    - `cabinet_id` → `oz_cabinets.id` (`ON DELETE CASCADE`)
    - Legacy-таблица `oz_price_calc_cabinets` удалена; старый FK на неё ломал синхронизацию (MySQL 1452)

---

## 3. Web-маршруты

Prefix: `/panel/oz/price-calc` · middleware `permission:subscriber oz price calc`

| Method | URL | Назначение |
|--------|-----|------------|
| GET | `/` | Workspace (FBO/FBS по `?mode=`) |
| POST | `/sync`, `/calculate`, `/import`, `/export` | FBO actions |
| GET | `/export-download` | FBO download |
| POST | `/fbs/sync|calculate|import|export` | FBS actions |
| GET | `/fbs/export-download` | FBS download |

Legacy `/cabinets/{id}/*` → redirect на flat URL.

Контроллер: `Web/Subscriber/Oz/PriceCalc/WorkspaceController` + trait `ResolvesSelectedOzCabinet`.

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
2. **`% выкупа` (`buyout_percent`)** — ручное поле, источник Excel-импорт (`заполняется`). Расчёт его не перезаписывает.
3. **Средний тариф (`logistics_fbs`)** — та же ступенчатая таблица, что у FBO, см. [§ 6.6](#66-средний-тариф-по-кластерам-сентябрь-2026).
4. **Средний тариф с учетом % выкупа (`logistics_fbs_over_190`)**:
   `round((logistics_fbs * 100 + (100 - buyout_percent) * logistics_fbs) / buyout_percent)`.
5. **Формирование цен:**
    - **`stop_price`**: `= (Себестоимость * (1 + Маржа) + Фулфилмент) / (1 - Доп.расходы)`.
    - **`min_price`**: `ceil((stop_price + logistics_fbs_over_190 + 55) / (1 - (налог + комиссия + реклама + 1.5) / 100))`.
    - **`current_price`**: `= min_price / (1 - ПроцентРекламы)`.

### Для схемы FBO

1. **Средний тариф по кластерам (`logistics_fbo`)** — ступенчатая таблица от объёма (л), см. [§ 6.6](#66-средний-тариф-по-кластерам-сентябрь-2026).
2. **Средний тариф с учетом % выкупа (`logistics_fbo_over_190`)** — формула не менялась:
   `round((logistics_fbo * 100 + (100 - buyout_percent) * logistics_fbo) / buyout_percent)`.
   Считает от нового `logistics_fbo`.
3. **Стоимость приемки (`acceptance_fbo`)** = `5 руб. + (Объем - 1)`.
4. **Формирование цен**:
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
- С сентября 2026 `min_price` считается с константой `+55` (не `+65`).

### 6.4. Логистика (FBO/FBS)

- FBO и FBS: средний тариф прямой логистики — общая таблица, см. [§ 6.6](#66-средний-тариф-по-кластерам-сентябрь-2026).
- `logistics_*_over_190` — логистика с учетом выкупа (формула общая):
    - `round((logistics * 100 + (100 - buyout_percent) * logistics) / buyout_percent)`.

### 6.5. Округления (синхронизированы с Excel)

- `ОКРУГЛВВЕРХ` => `ceil`.
- `ОКРУГЛ` => `round`.

### 6.6. Средний тариф по кластерам (сентябрь 2026)

Класс: `App\Support\Ozon\PriceCalc\OzonClusterAverageLogisticsTariff`.

Колонки в UI / Excel:

| Режим | key | Название |
|-------|-----|----------|
| FBO | `logistics_fbo` | Средний тариф по кластерам (руб) |
| FBO | `logistics_fbo_over_190` | Средний тариф с учетом % выкупа |
| FBS | `logistics_fbs` | Средний тариф |
| FBS | `logistics_fbs_over_190` | Средний тариф с учетом % выкупа |

Тариф `logistics_fbo` по объёму (`volume_liters`, «до N л включительно»):

| Объём, л | Тариф, руб |
|----------|------------|
| ≤ 1 | 88.54 |
| ≤ 2 | 110.10 |
| ≤ 4 | 128.85 |
| ≤ 6 | 170.02 |
| ≤ 8 | 188.61 |
| ≤ 10 | 199.89 |
| ≤ 13 | 210.10 |
| ≤ 14 | 226.90 |
| ≤ 15 | 245.97 |
| ≤ 17 | 267.34 |
| ≤ 20 | 298.49 |
| ≤ 25 | 348.20 |
| ≤ 30 | 406.70 |
| ≤ 35 | 466.82 |
| ≤ 40 | 527.92 |
| ≤ 45 | 588.96 |
| ≤ 50 | 653.76 |
| ≤ 60 | 705.99 |
| ≤ 70 | 822.61 |
| ≤ 80 | 930.30 |
| ≤ 90 | 1070.52 |
| ≤ 100 | 1177.49 |
| ≤ 125 | 1371.07 |
| ≤ 150 | 1636.36 |
| ≤ 175 | 1904.23 |
| ≤ 200 | 2195.72 |
| ≤ 400 | 3231.82 |
| ≤ 600 | 5049.96 |
| ≤ 800 | 6588.77 |
| > 800 | 7856.33 |

Формула `logistics_*_over_190` не менялась; на вход идёт новый средний тариф (копейки не отбрасываются). `% выкупа` для FBS берётся из Excel-импорта.

---

## 7. Маппинг колонок для фронта

См. отдельный справочник: [ozon-price-calculation-frontend-columns.md](ozon-price-calculation-frontend-columns.md)
