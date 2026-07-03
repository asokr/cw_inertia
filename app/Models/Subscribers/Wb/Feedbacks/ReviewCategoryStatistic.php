<?php

namespace App\Models\Subscribers\Wb\Feedbacks;

use Illuminate\Database\Eloquent\Model;

class ReviewCategoryStatistic extends Model
{
    protected $table = 'wb_feedbacks_review_category_statistics';

    protected $fillable = [
        'cabinet_id',
        'subject_name',
        'date',
        'stat_type',
        'stat_data',
    ];

    protected $casts = [
        'date' => 'date',
        'stat_data' => 'array',
    ];
}
