<?php

namespace App\Models\Subscribers\Oz\Feedbacks;

use Carbon\Carbon;
use App\Casts\EncryptCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FeedbacksClients extends Model
{
    use HasFactory;

    protected $table = 'oz_feedbacks_clients';
    protected $fillable = [
        'user_id',
        'name',
        'apikey',
        'client_id',
        'bot_status',
        'ai_status',
        'ai_ratings',
        'signature',
        'empty_answer',
    ];
    protected $casts = [
        'apikey' => EncryptCast::class,
    ];
    protected function aiRatings(): Attribute
    {
        return Attribute::make(
            get: fn($value) => $value ? json_decode($value, true) : [],
            set: fn($value) => json_encode($value),
        );
    }
    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Europe/Moscow')->format('d.m.Y H:i');
    }
}
