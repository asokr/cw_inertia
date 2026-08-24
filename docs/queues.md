# Очереди проекта

Справочник **именованных очередей** Laravel и jobs, которые в них попадают.

**При изменении или добавлении очереди / job — обновляй этот файл** (имя очереди, класс job, timeout/tries, где диспатчится). Также обнови раздел воркеров ниже, если меняется рекомендуемая команда `queue:work`.

Связанные документы: [README.md](README.md), описания инструментов (`wb-*.md`, `oz-*.md`, `ozon-*.md`).

## Общие сведения

| Параметр | Значение |
|----------|----------|
| Connection (prod/local) | `QUEUE_CONNECTION=database` (см. `.env`) |
| Default queue name | `default` (если job не вызывает `onQueue(...)`) |
| Failed jobs | таблица `failed_jobs` (`config/queue.php`) |
| Job batches | таблица `job_batches` (Ozon Price Calc) |
| `retry_after` | **`QUEUE_RETRY_AFTER`**, по умолчанию **3700** с (см. ниже) |

Конфиг: [`config/queue.php`](../config/queue.php).  
Jobs: [`app/Jobs/`](../app/Jobs/).

### Важно: `retry_after` и длинные job

Для `database` / `redis` / `beanstalkd` значение **`retry_after` должно быть больше максимального `$timeout` job** (у AI-анализаторов и части Ozon/WB job — **3600** с).

Если `retry_after` меньше (раньше в проекте было **90**), воркер считает job «зависшим» и **снова выдаёт тот же payload** другому (или тому же) процессу. Симптом: один ИИ-анализ длится N минут, а в логах AI — полный dataset **каждые ~1–1.5 мин** (дубли запросов, лишние токены).

- Env: `QUEUE_RETRY_AFTER=3700` (см. `.env.example`)
- После смены — `php artisan config:clear` / `config:cache` и **перезапуск** `queue:work`

---

## Сводка очередей

| Очередь | Модуль | Jobs | Notes |
|---------|--------|------|-------|
| `default` | Разное | Ozon Price Calc, A/B ratings, почта, notifications | Всё без явного `onQueue` |
| `price_calc` | WB Ценообразование V3 | `ProcessPriceCalcJob` | Долгие sync/import |
| `profitability` | WB Рентабельность | `ProcessProfitabilityReport`, `ExportProfitabilityReportJob` | |
| `repricer_stocks` | WB Репрайсер (остатки / strategy one) | `UpdateRepricerStocksJob`, `ApplyRepricerStrategyOneJob` | Unique jobs |
| `wb_ab_testing` | WB A/B-тесты | `ProcessAbExperimentJob` | Unique until processing |
| `oz_ab_testing` | Ozon A/B-тесты | `ProcessOzAbCabinetTickJob` | Unique until processing, один job на кабинет |
| `wb_ai_cabinet_analyzer` | WB AI Cabinet Analyzer | report + AI analysis jobs | timeout 3600 |
| `oz_ai_cabinet_analyzer` | Ozon AI Cabinet Analyzer | report + AI analysis jobs | timeout 3600 |

---

## По очередям

### `default`

Jobs **без** явного `onQueue` / `$this->queue`.

| Job | Timeout | Tries | Unique | Диспатч / примечание |
|-----|---------|-------|--------|----------------------|
| `App\Jobs\Ozon\CalculatePriceJob` | 3600 | default | — | `Bus::batch` из `OzPriceCalcFboService` / `OzPriceCalcFbsService` |
| `App\Jobs\Ozon\ExportPriceCalcJob` | 3600 | default | — | batch export |
| `App\Jobs\Ozon\ImportPriceCalcJob` | 3600 | default | — | batch import |
| `App\Jobs\Ozon\SyncPriceCalcJob` | 3600 | default | — | batch sync |
| `App\Jobs\Wb\AbTesting\EnrichAbProductRatingsJob` | 900 | 3 | `ShouldBeUnique` (`uniqueFor` 3600) | `WbAbTestingService` |
| `App\Jobs\SendContactFormEmail` | default | default | — | чаще `dispatchSync` (контакт/support) |
| Notifications (`ShouldQueue`) | default | default | — | почтовые уведомления (`app/Notifications/*`) |

Ozon Price Calc: batch names вида `ozon_fbo_*_{cabinetId}` / `ozon_fbs_*_{cabinetId}`.

### `price_calc`

| Job | Timeout | Tries | Unique | Где задаётся очередь |
|-----|---------|-------|--------|----------------------|
| `App\Jobs\Wb\PriceCalc\ProcessPriceCalcJob` | 2700 | 1 | — | `$this->onQueue('price_calc')` в конструкторе |

Диспатч: `WbPriceCalculationV3Service`.  
Документация: [wb-price-calculation-v3.md](wb-price-calculation-v3.md).

### `profitability`

| Job | Timeout | Tries | Unique | Где задаётся очередь |
|-----|---------|-------|--------|----------------------|
| `App\Jobs\ProcessProfitabilityReport` | 1800 | 1 | — | `->onQueue('profitability')` при dispatch |
| `App\Jobs\ExportProfitabilityReportJob` | 1800 | 1 | — | `$this->onQueue('profitability')` в конструкторе |

Диспатч: `WbProfitabilityReportService`.  
Документация: [wb-profitability.md](wb-profitability.md).

### `repricer_stocks`

| Job | Timeout | Tries | Unique | Где задаётся очередь |
|-----|---------|-------|--------|----------------------|
| `App\Jobs\UpdateRepricerStocksJob` | 1500 | default | `ShouldBeUnique` (`uniqueFor` 1800) | `$this->onQueue('repricer_stocks')` |
| `App\Jobs\ApplyRepricerStrategyOneJob` | default | default | `ShouldBeUnique` (`uniqueFor` 1800) | `$this->onQueue('repricer_stocks')` |

Диспатч: artisan-команды `DispatchRepricerStocksJobCommand`, `DispatchRepricerStrategyOneJobCommand` (+ self-reschedule внутри jobs).  
Документация: [wb-repricer.md](wb-repricer.md).

### `wb_ab_testing`

| Job | Timeout | Tries | Unique | Где задаётся очередь |
|-----|---------|-------|--------|----------------------|
| `App\Jobs\Wb\AbTesting\ProcessAbExperimentJob` | 120 | 1 | `ShouldBeUniqueUntilProcessing` (`uniqueFor` 120) | `$this->onQueue('wb_ab_testing')` |

Диспатч: `WbAbExperimentEngine`, `WbAbTestingTickCommand`, self-dispatch с delay.  
`EnrichAbProductRatingsJob` — в `default` (см. выше).  
Документация: [wb-ab-testing.md](wb-ab-testing.md).

### `oz_ab_testing`

| Job | Timeout | Tries | Unique | Где задаётся очередь |
|-----|---------|-------|--------|----------------------|
| `App\Jobs\Oz\AbTesting\ProcessOzAbCabinetTickJob` | 120 | 1 | `ShouldBeUniqueUntilProcessing` (`uniqueFor` 180, id `oz-ab-cabinet-{cabinetId}`) | `$this->onQueue('oz_ab_testing')` |
| `App\Jobs\Oz\AbTesting\ProcessOzAbExperimentJob` | 30 | 1 | shim: перекладывает в cabinet tick | `$this->onQueue('oz_ab_testing')` |

Диспатч: первый running-эксперимент кабинета → `ProcessOzAbCabinetTickJob`; следующие running только добавляются в тот же тик. Self-dispatch с delay **60 сек**.  
Fallback-команда: каждые 2 минуты, по одному job на кабинет.  
Документация: [oz-ab-testing.md](oz-ab-testing.md).

### `wb_ai_cabinet_analyzer`

| Job | Timeout | Tries | Unique / lock | Где задаётся очередь |
|-----|---------|-------|---------------|----------------------|
| `App\Jobs\Wb\AiCabinetAnalyzer\ProcessAiCabinetAnalyzerReport` | 3600 | 3 | `WithoutOverlapping` по `reportId` | `->onQueue('wb_ai_cabinet_analyzer')` при dispatch |
| `App\Jobs\Wb\AiCabinetAnalyzer\ProcessAiCabinetAnalyzerAiAnalysisJob` | 3600 | 3 | `WithoutOverlapping` по `analysisId` | `->onQueue('wb_ai_cabinet_analyzer')` при dispatch |

Диспатч: `WbAiCabinetAnalyzerReportsService`, `WbAiCabinetAnalyzerAiAnalysesService`.  
Backoff: `backoff()` в job (retry).  
Дополнительно: при `done` повторный handle выходит сразу; `failed` пишется только на **последней** попытке.  
Документация: [wb-ai-cabinet-analyzer.md](wb-ai-cabinet-analyzer.md).

### `oz_ai_cabinet_analyzer`

| Job | Timeout | Tries | Unique / lock | Где задаётся очередь |
|-----|---------|-------|---------------|----------------------|
| `App\Jobs\Oz\AiCabinetAnalyzer\ProcessOzAiCabinetAnalyzerReport` | 3600 | 3 | `WithoutOverlapping` по `reportId` | `->onQueue('oz_ai_cabinet_analyzer')` при dispatch |
| `App\Jobs\Oz\AiCabinetAnalyzer\ProcessOzAiCabinetAnalyzerAiAnalysisJob` | 3600 | 3 | `WithoutOverlapping` по `analysisId` | `->onQueue('oz_ai_cabinet_analyzer')` при dispatch |

Диспатч: `OzAiCabinetAnalyzerReportsService`, `OzAiCabinetAnalyzerAiAnalysesService`.  
Та же защита от дублей, что у WB (см. выше + `QUEUE_RETRY_AFTER`).  
Документация: [oz-ai-cabinet-analyzer.md](oz-ai-cabinet-analyzer.md).

---

## Рекомендуемые воркеры

Имена и timeout должны совпадать с jobs. Пример набора процессов (database driver):

```bash
# Общая / Ozon price calc / mail / A/B ratings
php artisan queue:work --queue=default --tries=3 --timeout=3600

# WB ценообразование
php artisan queue:work --queue=price_calc --tries=1 --timeout=2700

# WB рентабельность
php artisan queue:work --queue=profitability --tries=1 --timeout=1800

# WB репрайсер
php artisan queue:work --queue=repricer_stocks --timeout=1500

# WB A/B
php artisan queue:work --queue=wb_ab_testing,default --tries=1 --timeout=120

# Ozon A/B
php artisan queue:work --queue=oz_ab_testing,default --tries=1 --timeout=120

# AI-анализаторы
php artisan queue:work --queue=wb_ai_cabinet_analyzer --tries=3 --timeout=3600 --sleep=1
php artisan queue:work --queue=oz_ai_cabinet_analyzer --tries=3 --timeout=3600 --sleep=1
```

Один воркер на несколько очередей (приоритет слева направо):

```bash
php artisan queue:work --queue=wb_ab_testing,oz_ab_testing,price_calc,profitability,repricer_stocks,wb_ai_cabinet_analyzer,oz_ai_cabinet_analyzer,default --timeout=3600
```

Для локальной отладки без воркеров: `QUEUE_CONNECTION=sync` (jobs выполняются синхронно; batch/delay ведут себя иначе).

---

## Как обновлять этот файл

1. **Новая очередь** — добавь строку в «Сводка очередей» и секцию «По очередям».
2. **Новый job** — укажи класс, очередь, timeout/tries/unique, точку dispatch.
3. **Переименование / смена очереди у job** — поправь таблицы и команды воркеров.
4. **Удаление job/очереди** — убери из таблиц, не оставляй «мертвые» имена.
5. При необходимости синхронизируй упоминания `queue:work` в docs конкретного инструмента.
