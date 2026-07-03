<?php

namespace App\Models\Subscribers;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Models\Subscribers\SubscribersSubscriptions;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SubscribersPlans extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'price',
        'duration',
        'description',
        'limits_plan',
        'limits_month',
        'permissions',
        'status',
        'hidden'
    ];

    protected $casts = [
        'price' => 'float',
        'permissions' => 'json'
    ];

    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->setTimezone('Europe/Moscow')->format('d.m.Y H:i');
    }
    protected function limitsPlan(): Attribute
    {
        return new Attribute(
            get: fn($value) => json_decode($value, true),
            set: fn($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
        );
    }

    protected function limitsMonth(): Attribute
    {
        return new Attribute(
            get: fn($value) => json_decode($value, true),
            set: fn($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
        );
    }

    public function subscriptions()
    {
        return $this->belongsTo(SubscribersSubscriptions::class, 'id', 'plan_id');
    }
}
