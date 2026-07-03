<?php

namespace App\Models\Subscribers\Oz\PriceCalc;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OzPriceCalcFbo extends Model
{
    use HasFactory;

    protected $table = 'oz_price_calc_fbo';

    protected $fillable = [
        'cabinet_id',
        'ozon_article',
        'barcode',
        'cost_price',
        'margin_percent',
        'fulfillment_fee',
        'dop_rashod_percent',
        'stop_price',
        'weight_kg',
        'length_cm',
        'width_cm',
        'height_cm',
        'volume_liters',
        'logistics_markup_percent',
        'buyout_percent',
        'logistics_fbo',
        'logistics_fbo_over_190',
        'acceptance_fbo',
        'price_markup_for_logistics_percent',
        'dopakovka_rub',
        'tax_percent',
        'commission_percent',
        'advertising_percent',
        'promotion_percent',
        'min_price',
        'current_price',
    ];

    protected $casts = [
        'cost_price' => 'float',
        'margin_percent' => 'float',
        'fulfillment_fee' => 'float',
        'dop_rashod_percent' => 'float',
        'stop_price' => 'float',
        'weight_kg' => 'float',
        'length_cm' => 'float',
        'width_cm' => 'float',
        'height_cm' => 'float',
        'volume_liters' => 'float',
        'logistics_markup_percent' => 'float',
        'buyout_percent' => 'float',
        'logistics_fbo' => 'float',
        'logistics_fbo_over_190' => 'float',
        'acceptance_fbo' => 'float',
        'price_markup_for_logistics_percent' => 'float',
        'dopakovka_rub' => 'float',
        'tax_percent' => 'float',
        'commission_percent' => 'float',
        'advertising_percent' => 'float',
        'promotion_percent' => 'float',
        'min_price' => 'float',
        'current_price' => 'float',
    ];

    public function cabinet()
    {
        return $this->belongsTo(OzPriceCalcCabinet::class, 'cabinet_id');
    }
}
