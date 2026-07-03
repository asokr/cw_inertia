<?php

namespace App\Support\Ozon\PriceCalc;

final class OzonPriceCalcColumns
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forType(string $type): array
    {
        return $type === 'fbs' ? self::fbs() : self::fbo();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function fbo(): array
    {
        return [
            self::col('ozon_article', 'Артикул OZON', '', 'рассчёт', '#E2F0FF', '#1F4E79', ['артикул ozon']),
            self::col('barcode', 'Штрихкод', '', 'рассчёт', '#F2DCDB', '#9C0006', ['штрихкод']),
            self::col('cost_price', 'себест-ть', 'руб', 'заполняется', '#D8E4BC', null, ['себестоимость', 'себестоимость (руб)']),
            self::col('margin_percent', 'маржа %', '%', 'заполняется', '#D8E4BC', null, ['маржа %']),
            self::col('fulfillment_fee', 'ФФ', 'руб', 'заполняется', '#D8E4BC', null, ['фф (руб)', 'фф']),
            self::col('dop_rashod_percent', 'доп. расходы %', '%', 'заполняется', '#D8E4BC', null, ['% creative wave', 'доп.расходы %']),
            self::col('stop_price', 'СТОП-Цена', 'руб', 'рассчёт', '#A9D18E', null, ['стоп-цена', 'стоп-цена (расчет)']),
            self::col('weight_kg', 'вес (кг)', 'кг', 'заполняется', '#FFF2CC', null, ['вес', 'вес (кг)']),
            self::col('length_cm', 'длина (см)', 'см', 'заполняется', '#FFF2CC', null, ['длина', 'длина (см)']),
            self::col('width_cm', 'ширина (см)', 'см', 'заполняется', '#FFF2CC', null, ['ширина', 'ширина (см)']),
            self::col('height_cm', 'высота (см)', 'см', 'заполняется', '#FFF2CC', null, ['высота', 'высота (см)']),
            self::col('volume_liters', 'Объем (л)', 'л', 'рассчёт', '#FFF2CC', null, ['объем', 'объем (л)']),
            self::col('buyout_percent', '% выкупа', '%', 'заполняется', '#FFF2CC', null, ['% выкупа']),
            self::col('logistics_fbo', 'ЛОГИСТИКА FBO', 'руб', 'рассчёт', '#FFD966', null, ['логистика', 'логистика (руб)']),
            self::col('logistics_fbo_over_190', 'Логистика+обратная логистика FBO', 'руб', 'рассчёт', '#FFD966', null, ['логистика с учетом выкупа']),
            self::col('acceptance_fbo', 'приемка FBO', 'руб', 'рассчёт', '#B8CCE4', null, ['приемка', 'приемка (руб)']),
            self::col('price_markup_for_logistics_percent', 'надбавка к цене за логистику FBO', '%', 'заполняется', '#B8CCE4', null, ['надбавка к цене за логистику %']),
            self::col('dopakovka_rub', 'Доупаковка товаров на FBO', 'руб', 'заполняется', '#B8CCE4', null, ['доупаковка', 'доупаковка (руб)']),
            self::col('tax_percent', 'налог', '%', 'заполняется', '#DCE6F1', null, ['налог %']),
            self::col('commission_percent', 'Комиссия OZON FBO', '%', 'заполняется', '#DCE6F1', null, ['комиссия ozon %', 'комиссия ozon fbo', 'комиссия %']),
            self::col('advertising_percent', 'реклама', '%', 'заполняется', '#DCE6F1', null, ['реклама %']),
            self::col('promotion_percent', 'акции %', '%', 'заполняется', '#F4E1D2', null, ['акции %']),
            self::col('min_price', 'Минимальная цена', 'руб', 'рассчёт', '#4F81BD', '#FFFFFF', ['минимальная цена']),
            self::col('current_price', 'текущая цена', 'руб', 'рассчёт', '#F4B084', null, ['текущая цена']),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function fbs(): array
    {
        return [
            self::col('ozon_article', 'Артикул OZON', '', 'рассчёт', '#E2F0FF', '#1F4E79', ['артикул ozon']),
            self::col('barcode', 'Штрихкод', '', 'рассчёт', '#F2DCDB', '#9C0006', ['штрихкод']),
            self::col('cost_price', 'себест-ть', 'руб', 'заполняется', '#D8E4BC', null, ['себестоимость']),
            self::col('margin_percent', 'маржа %', '%', 'заполняется', '#D8E4BC', null, ['маржа %']),
            self::col('fulfillment_fee', 'ФФ', 'руб', 'заполняется', '#D8E4BC', null, ['фф']),
            self::col('dop_rashod_percent', 'доп. расходы %', '%', 'заполняется', '#D8E4BC', null, ['% creative wave', 'доп.расходы %']),
            self::col('stop_price', 'СТОП-Цена', 'руб', 'рассчёт', '#A9D18E', null, ['стоп-цена']),
            self::col('weight_kg', 'вес (кг)', 'кг', 'заполняется', '#FFF2CC', null, ['вес', 'вес (кг)']),
            self::col('length_cm', 'длина (см)', 'см', 'заполняется', '#FFF2CC', null, ['длина', 'длина (см)']),
            self::col('width_cm', 'ширина (см)', 'см', 'заполняется', '#FFF2CC', null, ['ширина', 'ширина (см)']),
            self::col('height_cm', 'высота (см)', 'см', 'заполняется', '#FFF2CC', null, ['высота', 'высота (см)']),
            self::col('volume_liters', 'Объем (л)', 'л', 'рассчёт', '#FFF2CC', null, ['объем', 'объем (л)']),
            self::col('buyout_percent', '% выкупа', '%', 'заполняется', '#FFF2CC', null, ['% выкупа']),
            self::col('logistics_fbs', 'ЛОГИСТИКА FBS', 'руб', 'рассчёт', '#FFD966', null, ['логистика', 'логистика (руб)']),
            self::col('logistics_fbs_over_190', 'Логистика+обратная логистика FBS', 'руб', 'рассчёт', '#FFD966', null, ['логистика с учетом выкупа']),
            self::col('tax_percent', 'налог', '%', 'заполняется', '#DCE6F1', null, ['налог %']),
            self::col('commission_percent', 'Комиссия OZON FBS', '%', 'заполняется', '#DCE6F1', null, ['комиссия ozon %', 'комиссия ozon fbs', 'комиссия %']),
            self::col('advertising_percent', 'реклама', '%', 'заполняется', '#DCE6F1', null, ['реклама %']),
            self::col('promotion_percent', 'акции %', '%', 'заполняется', '#F4E1D2', null, ['акции %']),
            self::col('min_price', 'Минимальная цена', 'руб', 'рассчёт', '#4F81BD', '#FFFFFF', ['минимальная цена']),
            self::col('current_price', 'текущая цена', 'руб', 'рассчёт', '#F4B084', null, ['текущая цена']),
        ];
    }

    /**
     * @param array<int, string> $aliases
     *
     * @return array<string, mixed>
     */
    private static function col(
        string $key,
        string $title,
        string $unit,
        string $mode,
        string $color,
        ?string $fontColor = null,
        array $aliases = []
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'unit' => $unit,
            'mode' => $mode,
            'color' => $color,
            'font_color' => $fontColor,
            'aliases' => $aliases,
        ];
    }
}
