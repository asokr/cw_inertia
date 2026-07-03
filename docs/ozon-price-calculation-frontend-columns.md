# Ozon Price Calc: колонки для фронта

Справочник колонок таблиц инструмента [ozon-price-calculation.md](ozon-price-calculation.md) для Nuxt-фронта.

Цвет колонок (`color`, `font_color`) приходит с backend — фронт должен брать цвет от backend, не задавать локально.

## FBO

| key | Название колонки (RU) |
|-----|----------------------|
| ozon_article | Артикул OZON |
| barcode | Штрихкод |
| cost_price | себест-ть |
| margin_percent | маржа % |
| fulfillment_fee | ФФ |
| dop_rashod_percent | доп. расходы % |
| stop_price | СТОП-Цена |
| weight_kg | вес (кг) |
| length_cm | длина (см) |
| width_cm | ширина (см) |
| height_cm | высота (см) |
| volume_liters | Объем (л) |
| buyout_percent | % выкупа |
| logistics_fbo | ЛОГИСТИКА FBO |
| logistics_fbo_over_190 | Логистика+обратная логистика FBO |
| acceptance_fbo | приемка FBO |
| price_markup_for_logistics_percent | надбавка к цене за логистику FBO |
| dopakovka_rub | Доупаковка товаров на FBO |
| tax_percent | налог |
| commission_percent | Комиссия OZON FBO |
| advertising_percent | реклама |
| promotion_percent | акции % |
| min_price | Минимальная цена |
| current_price | текущая цена |

## FBS

| key | Название колонки (RU) |
|-----|----------------------|
| ozon_article | Артикул OZON |
| barcode | Штрихкод |
| cost_price | себест-ть |
| margin_percent | маржа % |
| fulfillment_fee | ФФ |
| dop_rashod_percent | доп. расходы % |
| stop_price | СТОП-Цена |
| weight_kg | вес (кг) |
| length_cm | длина (см) |
| width_cm | ширина (см) |
| height_cm | высота (см) |
| volume_liters | Объем (л) |
| buyout_percent | % выкупа |
| logistics_fbs | ЛОГИСТИКА FBS |
| logistics_fbs_over_190 | Логистика+обратная логистика FBS |
| tax_percent | налог |
| commission_percent | Комиссия OZON FBS |
| advertising_percent | реклама |
| promotion_percent | акции % |
| min_price | Минимальная цена |
| current_price | текущая цена |

## Payload API

Для `GET /subscriber/oz/price-calc/cabinets/{cabinetId}/fbo` и `GET /subscriber/oz/price-calc/cabinets/{cabinetId}/fbs`:

```json
{
  "success": true,
  "messages": ["Список номенклатур"],
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "ozon_article": "abc",
        "barcode": "2000000000000",
        "updated_at": "2026-04-17 12:34:56"
      }
    ],
    "per_page": 25,
    "total": 100
  },
  "columns": [
    {
      "key": "ozon_article",
      "title": "Артикул OZON",
      "unit": "",
      "color": "#E2F0FF",
      "font_color": "#1F4E79"
    }
  ]
}
```

Важно:

- `columns` — отдельный массив в ответе;
- `columns[*].unit` всегда присутствует (пустая строка, если единица не применяется);
- цвета берутся из `columns[*].color` и `columns[*].font_color`;
- в каждом item есть `updated_at`;
- `data` — стандартная Laravel-пагинация.