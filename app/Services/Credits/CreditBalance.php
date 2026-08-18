<?php

namespace App\Services\Credits;

readonly class CreditBalance
{
    public function __construct(
        public int $subscription,
        public int $purchased,
        public int $subscriptionHeld,
        public int $purchasedHeld,
        public int $planPerPeriod,
    ) {
    }

    public function available(): int
    {
        return max(0, $this->subscription + $this->purchased - $this->subscriptionHeld - $this->purchasedHeld);
    }

    public function held(): int
    {
        return $this->subscriptionHeld + $this->purchasedHeld;
    }

    /**
     * Данные для frontend / shared props.
     *
     * @return array{
     *     available: int,
     *     subscription: int,
     *     purchased: int,
     *     held: int,
     *     plan_per_period: int
     * }
     */
    public function toFrontendArray(): array
    {
        return [
            'available' => $this->available(),
            'subscription' => $this->subscription,
            'purchased' => $this->purchased,
            'held' => $this->held(),
            'plan_per_period' => $this->planPerPeriod,
        ];
    }
}
