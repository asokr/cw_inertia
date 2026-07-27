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
        'review_type',
        'is_migrated',
        'migrated_at',
        'wb_cabinet_id',
    ];
    protected $casts = [
        'apikey' => EncryptCast::class,
        'ai_ratings' => 'array',
        'is_migrated' => 'boolean',
        'migrated_at' => 'datetime',
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
