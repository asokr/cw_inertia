<?php

namespace App\Services;

use Exception;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Coupon;
use App\Models\CouponUsage;

class CouponService
{

    /**
     * Calculate the discounted amount based on the coupon and original amount.
     *
     * @param Coupon $coupon The coupon object.
     * @param int $originalAmount The original amount.
     * @return int The discounted amount.
     */
    public function calculateDiscountedAmount(Coupon $coupon, int $originalAmount): int
    {
        $discountAmount = 0;

        if ($coupon->type === 'fixed') {
            $discountAmount = (int) $coupon->value;
        } elseif ($coupon->type === 'percentage') {
            $discountAmount = $this->calculatePercentageDiscount((int) $coupon->value, $originalAmount);
        }

        return $originalAmount - $discountAmount;
    }

    /**
     * Calculate the discounted amount based on a percentage and the original amount.
     *
     * @param int $percentage The percentage value.
     * @param int $originalAmount The original amount.
     * @return int The discounted amount.
     */
    private function calculatePercentageDiscount(int $percentage, int $originalAmount): int
    {
        return (int) ($percentage / 100 * $originalAmount);
    }

    /**
     * Minus coupon limit.
     *
     * @param Coupon $coupon The coupon object.
     */
    public function minusCouponLimit(Coupon $coupon)
    {
        if ($coupon->limit) {
            $coupon->limit--;
            $coupon->save();
        }
    }

    /**
     * Validates a coupon.
     *
     * @param string $code Код купона.
     * @throws Exception Куон не существует, или не начал своё действие, или просрочен, или лимит использования исчерпан.
     * @return Coupon $coupon The coupon object.
     */
    public function validateCoupon($code): Coupon
    {
        $coupon = Coupon::where('code', $code)->first();
        if (!$coupon)
            throw new Exception("Не верный промокод");

        $today = Carbon::now();

        if (!$coupon->start_date || $today < $coupon->start_date)
            throw new Exception("Промокод пока нельзя использовать. Может позже.");

        if ($coupon->end_date && $coupon->end_date < $today)
            throw new Exception("Промокод просрочен");

        if ($coupon->limit && $coupon->limit <= 0)
            throw new Exception("Вы больше не можете использовать этот промокод");

        return $coupon;
    }

    public function recordCouponUsage(User $user, Coupon $coupon, array $meta = []): void
    {
        CouponUsage::create([
            'user_id'   => $user->id,
            'coupon_id' => $coupon->id,
            'used_at'   => now(),
            'meta'      => $meta,
        ]);
    }
}
