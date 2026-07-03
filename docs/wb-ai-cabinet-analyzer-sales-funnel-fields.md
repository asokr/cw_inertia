# WB AiCabinet Analyzer: карта полей Sales Funnel (WB)

## Назначение

Единый справочник соответствия полей ответа WB Sales Funnel и внутренних полей `result_json.items[].funnel`.

Источник WB endpoint:

- `POST https://seller-analytics-api.wildberries.ru/api/analytics/v3/sales-funnel/products`

Используется в:

- [`app/Services/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerService.php`](../app/Services/Wb/AiCabinetAnalyzer/AiCabinetAnalyzerService.php)

## Связанные документы

- [wb-ai-cabinet-analyzer.md](wb-ai-cabinet-analyzer.md)

## Общая структура WB payload

Ожидаемая форма (по схеме WB):

- `data.products[]`
- `data.products[].product`
- `data.products[].statistic.selected`
- `data.products[].statistic.past`
- `data.products[].statistic.comparison`
- `data.products[].currency`

Ключ товара (`nmid`) берётся из:

- `data.products[].product.nmId`

## Маппинг WB -> внутренний funnel

### Период

- `statistic.selected.period.start` -> `funnel.period.selected.start`
- `statistic.selected.period.end` -> `funnel.period.selected.end`

### Основные метрики selected

- `statistic.selected.openCount` -> `funnel.open_count`
- `statistic.selected.cartCount` -> `funnel.cart_count`
- `statistic.selected.orderCount` -> `funnel.order_count`
- `statistic.selected.orderSum` -> `funnel.order_sum`
- `statistic.selected.buyoutCount` -> `funnel.buyout_count`
- `statistic.selected.buyoutSum` -> `funnel.buyout_sum`
- `statistic.selected.cancelCount` -> `funnel.cancel_count`
- `statistic.selected.cancelSum` -> `funnel.cancel_sum`
- `statistic.selected.avgPrice` -> `funnel.avg_price`
- `statistic.selected.avgOrdersCountPerDay` -> `funnel.avg_orders_count_per_day`
- `statistic.selected.shareOrderPercent` -> `funnel.share_order_percent`
- `statistic.selected.addToWishlist` -> `funnel.add_to_wishlist`
- `statistic.selected.localizationPercent` -> `funnel.localization_percent`

### Вложенный блок timeToReady

- `statistic.selected.timeToReady.days` -> `funnel.time_to_ready.days`
- `statistic.selected.timeToReady.hours` -> `funnel.time_to_ready.hours`
- `statistic.selected.timeToReady.mins` -> `funnel.time_to_ready.mins`

### Вложенный блок wbClub

- `statistic.selected.wbClub.orderCount` -> `funnel.wb_club.order_count`
- `statistic.selected.wbClub.orderSum` -> `funnel.wb_club.order_sum`
- `statistic.selected.wbClub.buyoutCount` -> `funnel.wb_club.buyout_count`
- `statistic.selected.wbClub.buyoutSum` -> `funnel.wb_club.buyout_sum`
- `statistic.selected.wbClub.cancelCount` -> `funnel.wb_club.cancel_count`
- `statistic.selected.wbClub.cancelSum` -> `funnel.wb_club.cancel_sum`
- `statistic.selected.wbClub.avgPrice` -> `funnel.wb_club.avg_price`
- `statistic.selected.wbClub.buyoutPercent` -> `funnel.wb_club.buyout_percent`
- `statistic.selected.wbClub.avgOrderCountPerDay` -> `funnel.wb_club.avg_order_count_per_day`

### Вложенный блок conversions

- `statistic.selected.conversions.addToCartPercent` -> `funnel.conversions.add_to_cart_percent`
- `statistic.selected.conversions.cartToOrderPercent` -> `funnel.conversions.cart_to_order_percent`
- `statistic.selected.conversions.buyoutPercent` -> `funnel.conversions.buyout_percent`

### Период сравнения и динамики

- `statistic.past` -> `funnel.past`
- `statistic.comparison` -> `funnel.comparison`

### Валюта

- `currency` -> `funnel.currency`

### Raw payload

- весь объект `data.products[]` -> `funnel.raw_funnel_payload`

## Что идёт в сопоставление ads_vs_funnel

- `items[].orders` (из рекламы) сравнивается с `items[].funnel.order_count`.
- Формируется:
  - `items[].ads_vs_funnel.orders_gap`
  - `items[].ads_vs_funnel.orders_ratio_ads_to_funnel`

## Примечания

- Если WB добавит новые поля в `selected/past/comparison`, они автоматически доступны в `raw_funnel_payload`.
- Для расширения нормализованной модели добавлять поля нужно в `normalizeFunnelRow()` в `AiCabinetAnalyzerService`.