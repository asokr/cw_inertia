<?php

namespace App\Models\Subscribers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SubscribersSubscriptionsControl extends Model
{
    use HasFactory;

    protected $table = 'subscribers_subscriptions_control';

    protected $fillable = [
        'subscription_id',
        'action',
        'config'
    ];

    protected function config(): Attribute
    {
        return new Attribute(
            get: fn($value) => json_decode($value, true),
            set: fn($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
        );
    }
}
