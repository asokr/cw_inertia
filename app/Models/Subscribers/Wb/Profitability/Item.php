<?php

namespace App\Models\Subscribers\Wb\Profitability;

use Illuminate\Database\Eloquent\Model;
use App\Models\Subscribers\Wb\Profitability\Report;

class Item extends Model
{
    protected $table = 'wb_profitability_items';

    protected $fillable = [
        'report_id',
        'nm_id',
        'sa_name',
        'supplier_oper_name',
        'reasoning',
        'size',
        'barcode',
        'warehouse',
        'quantity',
        'price_without_spp',
        'sum_to_transfer',
        'purchase_cost',
        'logistics',
        'cost_adjustments',
        'dop_rashod',
        'cashback',
        'nalog',
        'margin',
        'profitability_percent',
    ];

    protected $casts = [
        'sum_to_transfer' => 'float',
        'purchase_cost' => 'float',
        'logistics' => 'float',
        'cost_adjustments' => 'float',
        'dop_rashod' => 'float',
        'cashback' => 'float',
        'nalog' => 'float',
        'margin' => 'float',
        'profitability_percent' => 'float',
    ];

    /**
     * Родительский отчёт
     */
    public function report()
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
}
