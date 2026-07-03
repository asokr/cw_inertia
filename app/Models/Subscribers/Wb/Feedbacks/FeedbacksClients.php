<?php

namespace App\Models\Subscribers\Wb\Feedbacks;

use Carbon\Carbon;
use App\Casts\EncryptCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FeedbacksClients extends Model
{
    use HasFactory;
    protected $table = 'subs_wb_feedbacks_clients';
    protected $fillable = [
        'subscriber_id',
        'name',
        'brands',
        'apikey',
        'bot_status',
        'ai_status',
        'ai_ratings',
        'review_type'
    ];
    protected $casts = [
        'apikey' => EncryptCast::class,
        'ai_ratings' => 'array',
    ];
    // protected function aiRatings(): Attribute
    // {
    //     return Attribute::make(
    //         get: fn($value) => $value ? json_decode($value, true) : [],
    //         set: fn($value) => json_encode($value),
    //     );
    // }
    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Europe/Moscow')->format('d.m.Y H:i');
    }

    public function subscriber()
    {
        return $this->belongsTo(\App\Models\Subscribers\Subscribers::class, 'subscriber_id');
    }
}
