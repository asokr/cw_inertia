<?php

namespace App\Models\Subscribers\Wb\Feedbacks;

use Illuminate\Database\Eloquent\Model;
use App\Models\Subscribers\Wb\WbCabinet;

class ReviewStatistic extends Model
{
    protected $table = 'wb_feedbacks_review_statistics';

    protected $fillable = [
        'cabinet_id',
        'date',
        'stat_type',
        'stat_data',
    ];

    protected $casts = [
        'date' => 'date',
        'stat_data' => 'array',
        'pros_cons_data' => 'array',
    ];

    public function cabinet()
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }
}
