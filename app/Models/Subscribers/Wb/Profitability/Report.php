<?php

namespace App\Models\Subscribers\Wb\Profitability;

use Illuminate\Database\Eloquent\Model;
use App\Models\Subscribers\Wb\Profitability\Item;
use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;

class Report extends Model
{
    protected $table = 'wb_profitability_reports';

    protected $fillable = [
        'cabinet_id',
        'date_from',
        'date_to',
        'sales_quantity',
        'sales_amount',
        'returns_quantity',
        'returns_amount',
        'percent_buy',
        'penalties',
        'logistics',
        'purchase_cost',
        'margin',
        'deduction',
        'storage_fee',
        'acceptance',
        'cashback',
        'dop_rashod',
        'nalog',
        'nalog_percent',
        'correction_sales',
        'total_profitability',
        'itog',
    ];

    protected $casts = [
        'sales_amount' => 'float',
        'returns_amount' => 'float',
        'percent_buy' => 'float',
        'penalties' => 'float',
        'logistics' => 'float',
        'purchase_cost' => 'float',
        'margin' => 'float',
        'deduction' => 'float',
        'storage_fee' => 'float',
        'acceptance' => 'float',
        'cashback' => 'float',
        'dop_rashod' => 'float',
        'nalog' => 'float',
        'nalog_percent' => 'float',
        'correction_sales' => 'float',
        'total_profitability' => 'float',
        'itog' => 'float',
    ];

    /**
     * Кабинет WB, к которому относится отчёт
     */
    public function cabinet()
    {
        return $this->belongsTo(ProfitabilityCabinet::class, 'cabinet_id');
    }

    /**
     * Позиции отчёта
     */
    public function items()
    {
        return $this->hasMany(Item::class, 'report_id');
    }
}
