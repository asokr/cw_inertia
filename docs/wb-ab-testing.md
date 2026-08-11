# WB A/B-тестирование

## Права доступа

- Permission: `subscriber wb ab testing`
- Middleware панели: `auth`, `verified`, `panel.access`, `wb.cabinets.migrated`, `permission:subscriber wb ab testing`

## Назначение

Инструмент для A/B-тестирования **главной фотографии** карточки товара Wildberries.

Кабинет WB — **единый** ([wb-cabinets.md](wb-cabinets.md)). Workspace flat: `/panel/wb/ab-testing` для **активного** кабинета.

### Текущий scope (MVP)

- Фундамент инструмента в панели
- **Flat workspace** (без wizard-шагов 3–5):
  - **Товары** → клик → **эксперименты товара** → клик → **workspace** (РК, настройки, фото, запуск/стоп, статистика, история)
- Одновременно **running** — не больше одного на товар
- **Фото:** 2–6 (private disk); настройки сохраняются кнопкой
- **`stopped`:** редактируемый + повторный запуск
- **Движок (фон):** schedule + queue — ротация фото, циклы, статистика, завершение
- **Live poll (5s)** на workspace при `status=running`: Inertia `only: selectedExperiment`
- **Stats на фото:** Показы / Клики / CTR из агрегатов циклов (вкл. provisional open-цикл после job tick). **% эффективности** и бейдж «Победитель» — **только при `completed`**, база = max CTR (не первое фото): `(CTR / best − 1) × 100`, победитель `0%`, остальные ≤ 0. Во время `running` — бейдж «Сбор статистики».
- **История действий:** таблица кругов (время установки, превью, вариант, клики, показы, CTR, круг, длительность / «В процессе»)

### Пока не реализовано / later

- Применение CPM в advert API при старте
- Восстановление исходного (дотеста) фото карточки при stop/error (победитель уже ставится при `completed`)
- Export CSV/XLSX истории действий

## Ключевые файлы

### Web (Inertia)

- `app/Http/Controllers/Web/Subscriber/Wb/AbTesting/WorkspaceController.php`
- Trait: `ResolvesSelectedWbCabinet`
- Requests:
  - `StoreAbExperimentRequest`
  - `UpdateAbExperimentRequest`
  - `StoreAbCampaignRequest`
  - `BindAbCampaignRequest`
  - `ModifyAbCampaignNmsRequest`
  - `StoreAbExperimentPhotosRequest`
  - `ReplaceAbExperimentPhotoRequest`
  - `ReorderAbExperimentPhotosRequest`
  - `UpdateAbExperimentSettingsRequest`

### Services / models / jobs

- `app/Services/Subscriber/Wb/WbAbTestingService.php` — HTTP facade (start/stop/map)
- `app/Services/Subscriber/Wb/AbTesting/WbAbExperimentEngine.php` — lifecycle engine
- `app/Services/Subscriber/Wb/AbTesting/WbAbExperimentJournal.php` — event log helper
- `app/Services/Wb/WbAdvertApiClient.php` — Promotion API: adverts, start, pause, fullstats
- `app/Services/Wb/WbContentMediaClient.php` — Content API `POST /content/v3/media/file`
- Models: `AbProduct`, `AbExperiment`, `AbCampaign`, `AbExperimentPhoto`, `AbExperimentCycle`, `AbExperimentEvent`
- `app/Enums/WbAbTestStatus.php` — draft / running / completed / **stopped** / error
- Jobs: `EnrichAbProductRatingsJob`, **`ProcessAbExperimentJob`**
- Command: `subscriber:wb-ab-testing-tick` (every minute in Kernel)

### UI

- `resources/js/Pages/Subscriber/Wb/AbTesting/Index.vue`
- `resources/js/components/subscriber/wb/ab-testing/*`
  - `CampaignSelectStep.vue`, `CampaignsTable.vue`, `CreateCampaignDialog.vue`, `campaignStatus.js`
  - `ExperimentWorkspace.vue` — единый экран эксперимента
  - `LaunchStep.vue` — старт / стоп / прогресс + `ExperimentActionHistory.vue`
  - `PhotosStep.vue`, `ExperimentPhotoCard.vue`, `ExperimentPhotoUploadZone.vue`, `photoResultTone.js`
  - `useAbExperimentPoll.js` — poll `selectedExperiment` while running

## Web routes

Prefix: `/panel/wb/ab-testing` · name: `subscriber.wb.ab-testing.*`

| Method | URL | Named route | Назначение |
|--------|-----|-------------|------------|
| GET | `/` | `index` | Wizard; `?product_id=` → product + experiments; `?experiment_id=` → selectedExperiment (+photos) |
| POST | `/sync` | `sync` | Синхронизация номенклатуры + цены; job рейтингов |
| POST | `/experiments` | `experiments.store` | Создать эксперимент (черновик) |
| PATCH | `/experiments/{id}` | `experiments.update` | Переименовать эксперимент (JSON) |
| PATCH | `/experiments/{id}/settings` | `experiments.settings` | Сохранение параметров эксперимента (кнопка «Сохранить настройки») |
| POST | `/experiments/{id}/start` | `experiments.start` | Запуск эксперимента (проверки + campaign + photo + cycle) |
| POST | `/experiments/{id}/stop` | `experiments.stop` | Остановка running → `stopped` |
| POST | `/experiments/{id}/campaign` | `experiments.campaign.bind` | Привязать кампанию (JSON; add product if needed) |
| GET | `/campaigns?experiment_id=` | `campaigns.index` | Список **наших** кампаний кабинета (JSON) |
| POST | `/campaigns` | `campaigns.store` | Создать кампанию WB + запись в реестр + bind (**без start**) |
| POST | `/campaigns/{advertId}/prepare` | `campaigns.prepare` | Swap nm под текущий товар + bind (клик по строке в UI) |
| POST | `/campaigns/{advertId}/nms` | `campaigns.nms.add` | Добавить товар (legacy/API) |
| DELETE | `/campaigns/{advertId}/nms` | `campaigns.nms.remove` | Удалить товар (legacy/API) |
| POST | `/campaigns/{advertId}/pause` | `campaigns.pause` | Пауза РК на WB |
| GET | `/campaigns/{advertId}/budget` | `campaigns.budget` | Текущий бюджет РК (`budget_total`) |
| POST | `/campaigns/{advertId}/deposit` | `campaigns.deposit` | Пополнение `{ sum }` → `budget_total`, `deposited_sum` + toast/статус в UI |
| DELETE | `/campaigns/{advertId}` | `campaigns.destroy` | Удалить РК на WB + из реестра |
| GET | `/media/{photo}` | `media.show` | Auth-gated превью (private disk); `?download=1` → `Content-Disposition: attachment` |
| GET | `/experiments/{id}/photos` | `photos.index` | Список фото (JSON) |
| POST | `/experiments/{id}/photos` | `photos.store` | Multipart upload (`photos[]`) |
| POST | `/experiments/{id}/photos/{photo}` | `photos.replace` | Замена файла (только editable) |
| DELETE | `/experiments/{id}/photos/{photo}` | `photos.destroy` | Удаление + compact order; **разрешено и для `running`** |
| PATCH | `/experiments/{id}/photos/reorder` | `photos.reorder` | `{ order: [id,…] }` (только editable) |

При отсутствии выбранного кабинета — `Subscriber/Wb/Shared/NoCabinet`.

Flash (shared Inertia): `created_experiment` — созданный эксперимент после POST.

## Кэш товаров

Таблица: `wb_ab_products`  
Модель: `App\Models\Subscribers\Wb\AbTesting\AbProduct`  
FK: `cabinet_id` → `wb_cabinets.id`

| Поле | Описание |
|------|----------|
| nm_id | Артикул WB |
| vendor_code | Артикул продавца |
| title | Название |
| brand | Бренд |
| subject_name | Категория (предмет) |
| photo_url | Превью из Content API |
| price | Цена (seller prices API при sync) |
| rating | Рейтинг по отзывам |
| rating_updated_at | Когда рейтинг обновляли |

### Синхронизация (sync)

1. **Content API** `POST /content/v2/get/cards/list` — карточки (title, brand, subject, vendorCode, photo).  
   **Не затирает** price/rating.
2. **Prices API** `GET discounts-prices-api /api/v2/list/goods/filter`
3. **Рейтинги (фон):** `EnrichAbProductRatingsJob` → Analytics item-rating v2

Поиск в UI: `nm_id`, `vendor_code`.

Статус теста в таблице товаров: по **последнему** эксперименту; без экспериментов — «Не создан».

## Схема БД (финальная)

**Итоговая миграция:** `database/migrations/2026_08_05_120000_create_wb_ab_testing_tables.php`  
(старые поэтапные `*wb_ab*` миграции можно удалить после rollback — не используются на prod)

### `wb_ab_experiments`

Модель: `App\Models\Subscribers\Wb\AbTesting\AbExperiment`

| Поле | Описание |
|------|----------|
| ab_product_id | FK → `wb_ab_products.id` CASCADE |
| cabinet_id | FK → `wb_cabinets.id` CASCADE |
| name | Название |
| status | `draft` / `running` / `completed` / `stopped` / `error` |
| progress | 0–100: draft setup 0/30/50/70; running = min(views_i/target)×100 (0–99); completed 100 |
| wb_advert_id / wb_advert_name / campaign_bound_at | Binding РК + snapshot имени |
| **impressions_per_photo** | Лимит показов **на одно фото** (default UI 100000) |
| impressions_per_round | Показов за круг (default 10000) |
| round_minutes | Длительность круга, мин (default 60) |
| cpm | CPM ₽ (default 350) |
| started_at | Старт running |
| **finished_at** | Любой terminal-статус (completed/stopped/error) |
| error_message | Текст ошибки |
| winner_photo_id | FK → photos NULL ON DELETE |
| last_processed_at / consecutive_failures | Engine tick state |

**Не храним:** `current_photo_id` / `current_cycle_id` — вычисляются из open cycle (`ended_at IS NULL`).  
**Не храним:** CTR и прочие производные — только raw counters в cycles.

### Остальные таблицы

| Таблица | Назначение |
|--------|------------|
| `wb_ab_products` | Кэш номенклатуры кабинета |
| `wb_ab_campaigns` | Реестр «наших» РК |
| `wb_ab_experiment_photos` | Варианты фото (private disk) |
| `wb_ab_experiment_cycles` | История раундов (views/clicks/spend start–end) |
| `wb_ab_experiment_events` | Журнал событий |

## Навигация (без wizard 3–5)

| View | URL | Содержимое |
|------|-----|------------|
| Товары | `/panel/wb/ab-testing` | Номенклатура |
| Эксперименты | `?product_id=` | Список экспериментов товара |
| Workspace | `?experiment_id=` | **Один экран:** РК, настройки, фото, старт/стоп, прогресс, журнал |

Сайдбар шагов **не используется**. Компонент: `ExperimentWorkspace.vue`.

Статусы: Не создан / Черновик / В процессе / **Завершён** / **Остановлен** / Ошибка.

| Статус | Редактирование (РК/settings/upload/replace/reorder) | Удаление фото | Скачивание фото | Старт | Стоп |
|--------|-----------------------------------------------------|---------------|-----------------|-------|------|
| draft | ✅ | ✅ | ✅ | ✅ | — |
| **stopped** | ✅ | ✅ | ✅ | ✅ (перезапуск, sequence++) | — |
| **error** | ✅ | ✅ | ✅ | ✅ (перезапуск, сброс consecutive_failures) | — |
| running | ❌ | ✅ (мин. 1 остаётся) | ✅ | — | ✅ |
| completed | ❌ | ❌ | ✅ | ❌ | — |

### Эксперименты товара

- Несколько черновиков на товар — **можно**.
- **Запущен (`running`)** — не больше **одного** на товар (`assertCanStartExperiment`).
- Клик по строке → workspace эксперимента.

### Движок эксперимента

**Запуск (HTTP):** проверки (draft, settings, ≥2 фото, campaign, nm в РК, статус РК 4/9/11) → `GET /adv/v1/budget` (total &lt; 1 ₽ → понятная RU-ошибка) → `GET /adv/v0/start` (если не 9) → `POST content/v3/media/file` (главное фото #1) → baseline `fullstats` → cycle #1 → `status=running`.

**Фон (primary):** после `start` сразу `ProcessAbExperimentJob` → `process` → self-reschedule через 60 с, пока `running`.  
**Fallback:** `subscriber:wb-ab-testing-tick` каждые **2** минуты (если цепочка job оборвалась).

**Смена фото (что раньше):** `delta_views ≥ impressions_per_round` **или** `elapsed ≥ round_minutes`. Не «минимум времени» — время это **верхняя** граница круга; показы могут сменить фото раньше. Порядок по `sort_order`, кольцо.

**Прогресс (running):** bottleneck `min_i(views_i / impressions_per_photo)` → 0–99%.  
Старт: `progress=0`. Пока суммарно 0 показов — `progress_mode=pending` (рыба в UI). После первых views — `views`. Completed → 100.  
Черновик: setup-ступени 30/50/70 (готовность wizard), не смешиваются с running.

**Завершение:** каждая фотография набрала `impressions_per_photo` (сумма Δ views по **всем** циклам) → pause РК → winner (max CTR) → **установка победителя главным фото** (`POST content/v3/media/file`, photo #1) → `completed` + `finished_at`. Если upload победителя упал — эксперимент всё равно `completed`, в journal/error_message — предупреждение.  
**Стоп пользователем:** pause РК → `stopped` + `finished_at` (не error).  
**Ошибки API:** `consecutive_failures`, после 5 non-rate-limit сбоев → `error` + `finished_at` + pause.  
**429 fullstats:** soft — `api.rate_limited`, **не** terminal; job reschedule по `retry_after` (≥20 с). Лимит WB: 3 req/min, interval 20 s, burst 1.  
**История UI:** last 100 cycles + `total_rounds`; агрегаты/progress **всегда** по всем cycles (не по limit 100).

**Open cycle:** единственный cycle с `ended_at IS NULL` (текущее фото = `ab_experiment_photo_id` цикла).  
**switchPhoto:** upload next photo **до** закрытия текущего цикла (иначе потеря open cycle).

**Инфра:** нужны `php artisan schedule:run` (или cron) и `php artisan queue:work --queue=wb_ab_testing,default`.

### Шаг 3 — рекламная кампания

**Принцип:** не выводим весь рекламный кабинет WB. Ведём реестр **своих** кампаний (`wb_ab_campaigns`) — только их показываем и ими управляем (в т.ч. для другого nm в том же кабинете).

#### Таблица `wb_ab_campaigns`

| Поле | Описание |
|------|----------|
| cabinet_id | FK кабинет |
| wb_advert_id | ID кампании WB (unique per cabinet) |
| name / bid_type / payment_type | snapshot |
| created_by_experiment_id | кто создал (nullable) |

#### API WB

[Promotion OpenAPI](https://dev.wildberries.ru/docs/openapi/promotion) · `https://advert-api.wildberries.ru`

| Операция | Endpoint |
|----------|----------|
| Детали наших ID | `GET /api/advert/v2/adverts?ids=` |
| Создать | `POST /adv/v2/seacat/save-ad` |
| Товары ± / prepare | `PATCH /adv/v0/auction/nms` |
| Бюджет | optional `POST /adv/v1/budget/deposit` (type=1 баланс; в UI пополнение **включено по умолчанию**, min 1000 ₽) |
| Бюджет (чтение) | `GET /adv/v1/budget` — preflight перед стартом эксперимента |
| Старт | **не** на шаге 3; на шаге 5 engine проверяет budget > 0 |

**Не** используем `promotion/count` для списка A/B.

#### can_edit_nms / prepare

Разрешено только если:

1. Кампания есть в `wb_ab_campaigns` этого кабинета  
2. WB-статус ∈ `{4, 11}` (готова / на паузе) — **не** `9` (активна)  
3. Нет эксперимента `status=running` с этим `wb_advert_id`

**UI действия (колонка «Действия»):** Пауза · Пополнить · Удалить (не действия с товаром).  
**Выбор кампании:** клик по строке → `prepare` (подставить nm эксперимента + bind).

**Сценарии:**

1. **Создать** → save-ad + запись в реестр + bind (+ optional deposit; fail deposit → `budget_deposited: false`, кампания всё равно привязана)
2. **Клик по строке** (`prepare`) → swap nm под товар эксперимента + bind  
3. **Пауза** — активная (status 9), не занята running A/B  
4. **Пополнить** — deposit min 1000 ₽  
5. **Удалить** — pause если active → delete на WB → unbind drafts → удалить из `wb_ab_campaigns`

CTR/CPM в таблице не грузятся.

Клиент: `App\Services\Wb\WbAdvertApiClient`.

### Шаг 4 — фотографии

**Назначение:** пользователь загружает варианты главной фотографии (не фото из карточки WB Content API).

| Ограничение | Значение |
|-------------|----------|
| Минимум для «Продолжить» / старта | 2 |
| Минимум во время `running` | 1 (удаление последнего запрещено) |
| Максимум | 6 |
| Форматы | JPEG, PNG, WEBP |
| Размер файла | ≤ 10 МБ |
| Upload / replace / reorder | `draft` / `stopped` / `error` |
| Удаление | `draft` / `stopped` / `error` / **`running`** |
| Скачивание | любой статус (`media.show?download=1`) |

#### Таблица `wb_ab_experiment_photos`

| Поле | Описание |
|------|----------|
| ab_experiment_id | FK эксперимент |
| cabinet_id | FK кабинет |
| sort_order | Порядок в эксперименте (0-based) |
| disk | `private` |
| path | relative path на private disk |
| original_name / mime / size | метаданные файла |

Хранение: `storage/app/private/wb/ab-testing/{cabinet_id}/{experiment_id}/{uuid}.ext`  
Превью: `GET /panel/wb/ab-testing/media/{photo}` (auth + ownership, `Cache-Control: private`) — **не** публичный `/storage/`.

#### Удаление фото на `running`

Сценарий: убрать вариант с низким CTR, чтобы показы шли на оставшиеся.

1. Проверки: статус `running`, ≥2 фото до удаления (после — ≥1), ownership.
2. `lockForUpdate` на эксперименте (без гонки с `ProcessAbExperimentJob`).
3. Если удаляемое = текущее (`open cycle`):
   - upload следующего оставшегося варианта в WB (`content/v3/media/file`, photo #1);
   - закрыть open cycle с `end_reason=photo_removed`;
   - открыть новый cycle на следующий вариант.
4. Удалить запись фото (CASCADE циклов этого фото) + compact `sort_order`.
5. Пересчитать `progress` по оставшимся; journal `photo.removed`.
6. Если остался 1 вариант — движок **не** ротирует, копится до `impressions_per_photo`, затем complete.

Payload: `can_delete_photos` = editable **или** running.

#### UI

- `PhotosStep.vue` — карточка товара, meta, **BoundCampaignPanel**, **ExperimentSettingsPanel**, empty state, upload zone, grid; подсказка про удаление на running; sticky «Продолжить» + причины блокировки
- `BoundCampaignPanel.vue` — управление привязанной РК
- `ExperimentPhotoCard.vue` — превью `object-contain`; toolbar **Скачать → Заменить → Удалить** (иконки Download / Replace / Trash2); бейдж «Сейчас на карточке»; DnD order; слоты Показы/Клики/CTR
- `photoResultTone.js` — provisional: green &lt;10%, yellow 10–30%, red &gt;30% (для будущего)
- `ExperimentSettingsPanel.vue` — collapsible (default closed), summary in header, badge «Не сохранены» / «Сохранены», **save only by button** (no autosave)
- Defaults: `100 000 на фото • 10 000 за круг • 60 мин • CPM 350 ₽` (поле `impressions_per_photo`)
- «Продолжить» → шаг 5 (Запуск), если ≥2 фото и `settings_ready` (после явного сохранения)

#### Будущая загрузка на WB

Приватный диск **не** мешает пушу в карточку: [mediaFiles](https://dev.wildberries.ru/docs/openapi/work-with-products#tag/mediaFiles)

| Endpoint | Для A/B |
|----------|---------|
| `POST /content/v3/media/file` | **Да** — binary с private disk на сервере (`X-Nm-Id`, `X-Photo-Number`) |
| `POST /content/v3/media/save` | **Нет** — требует публичные URL |

## Очереди и schedule

```
php artisan queue:work --queue=wb_ab_testing,default
# + cron: * * * * * php artisan schedule:run   # fallback tick every 2 min
```

| Job / command | Очередь | Назначение |
|---------------|---------|------------|
| `EnrichAbProductRatingsJob` | `default` | Рейтинги товаров |
| `ProcessAbExperimentJob` | **`wb_ab_testing`** | Тик одного running-эксперимента + self-reschedule 60 с |
| `subscriber:wb-ab-testing-tick` | — (schedule) | **everyTwoMinutes**, fallback после очистки очереди |

### Что делает `ProcessAbExperimentJob`

Один job = **один** эксперимент `running` (`ShouldBeUniqueUntilProcessing` по experiment id).

1. Старт эксперимента (HTTP) → **сразу** `dispatchFor(id)`.
2. `handle` → `WbAbExperimentEngine::process` (fullstats, смена фото, complete, journal).
3. Если статус всё ещё `running` → `dispatchFor(id, delay=60)`.
4. Fallback tick раз в 2 мин снова кладёт job, если цепочка потеряна.
5. Worker: `queue:work --queue=wb_ab_testing,default`.

## Связанные документы

- [wb-cabinets.md](wb-cabinets.md)
