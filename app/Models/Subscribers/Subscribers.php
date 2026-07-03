<?php

namespace App\Models\Subscribers;

use Carbon\Carbon;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use App\Models\Subscribers\SubscribersSubscriptions;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Subscribers extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status'
    ];
    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Europe/Moscow')->format('d.m.Y H:i');
    }
    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function getUser()
    {
        return User::find($this->user_id);
    }
    public function subscriptions()
    {
        return $this->hasMany(SubscribersSubscriptions::class, 'subscribers_id', 'id');
    }
}
