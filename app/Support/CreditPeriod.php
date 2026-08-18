<?php

namespace App\Support;

use App\Models\Subscribers\SubscribersSubscriptions;

class CreditPeriod
{
    public static function key(SubscribersSubscriptions $subscription): string
    {
        $start = $subscription->getRawOriginal('start_date')
            ?: $subscription->getRawOriginal('created_at')
            ?: now()->toDateTimeString();

        return sprintf('subscription:%d:period:%s', (int) $subscription->id, $start);
    }
}
