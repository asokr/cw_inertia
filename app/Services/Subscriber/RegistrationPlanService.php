<?php

namespace App\Services\Subscriber;

use App\Models\CouponUsage;
use App\Models\User;

class RegistrationPlanService
{
    public const DEFAULT_TEST_PLAN_ID = 2;

    public function resolveForUser(User $user): int
    {
        $usage = CouponUsage::query()
            ->where('user_id', $user->id)
            ->with('coupon')
            ->latest('id')
            ->first();

        if ($usage?->coupon) {
            $source = (string) ($usage->meta['source'] ?? '');

            if (str_contains($source, 'registration')) {
                return (int) $usage->coupon->value;
            }
        }

        return self::DEFAULT_TEST_PLAN_ID;
    }
}