<?php

namespace App\Models\Subscribers\Wb\Feedbacks;

use Illuminate\Database\Eloquent\Model;

class ReviewProductStatistic extends Model
{
    protected $table = 'wb_feedbacks_review_product_statistics';

    protected $fillable = [
        'cabinet_id',
        'product_id',
        'date',
        'stat_data',
        'pros_cons_data',
    ];

    protected $casts = [
        'stat_data' => 'array',
        'pros_cons_data' => 'array',
        'date' => 'date',
    ];

    public $timestamps = true;
}
