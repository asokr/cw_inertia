<?php

namespace App\Models\Subscribers\Wb\Feedbacks;

use App\Models\Subscribers\Wb\WbCabinet;

use Illuminate\Database\Eloquent\Model;
use App\Models\Subscribers\Wb\Feedbacks\BotResponse;

class Review extends Model
{
    protected $table = 'wb_feedbacks_reviews';

    protected $fillable = [
        'cabinet_id',
        'product_id',
        'rating',
        'content',
        'pros',
        'cons',
        'bables',
        'photo_links',
        'matching_size',
        'color',
        'subject_name',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'bables' => 'array',
        'photo_links' => 'array',
    ];


    public function cabinet()
    {
        return $this->belongsTo(WbCabinet::class, 'cabinet_id');
    }

    public function botResponse()
    {
        return $this->hasOne(BotResponse::class, 'review_id');
    }
}
