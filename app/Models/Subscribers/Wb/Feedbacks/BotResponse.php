<?php

namespace App\Models\Subscribers\Wb\Feedbacks;

use Illuminate\Database\Eloquent\Model;
use App\Models\Subscribers\Wb\Feedbacks\Review;

class BotResponse extends Model
{
    protected $table = 'wb_feedbacks_bot_responses';

    protected $fillable = [
        'review_id',
        'response_text',
        'is_ai_response',
        'created_at',
        'updated_at',
    ];

    public function review()
    {
        return $this->belongsTo(Review::class, 'review_id');
    }
}
