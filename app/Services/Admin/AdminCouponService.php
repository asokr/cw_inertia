<?php

namespace App\Services\Admin;

use App\Models\Coupon;
use Illuminate\Database\Eloquent\Collection;

class AdminCouponService
{
    public function all(): Collection
    {
        return Coupon::query()->orderByDesc('id')->get();
    }

    public function create(array $data): Coupon
    {
        return Coupon::create($data);
    }

    public function update(Coupon $coupon, array $data): Coupon
    {
        $coupon->update($data);

        return $coupon->fresh();
    }

    public function delete(Coupon $coupon): void
    {
        $coupon->delete();
    }
}