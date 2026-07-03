<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCouponRequest;
use App\Http\Requests\Admin\UpdateCouponRequest;
use App\Models\Coupon;
use App\Services\Admin\AdminCouponService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CouponController extends Controller
{
    public function __construct(private readonly AdminCouponService $couponService)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Admin/Coupons/Index', [
            'coupons' => $this->couponService->all(),
            'couponTypes' => [
                ['value' => 'fixed', 'label' => 'Фиксированная скидка'],
                ['value' => 'percentage', 'label' => 'Процент'],
                ['value' => 'registration', 'label' => 'Регистрация (бесплатный тариф)'],
            ],
        ]);
    }

    public function store(StoreCouponRequest $request): RedirectResponse
    {
        $this->couponService->create($request->validated());

        return redirect()->back()->with('success', 'Купон добавлен');
    }

    public function update(UpdateCouponRequest $request, Coupon $coupon): RedirectResponse
    {
        $this->couponService->update($coupon, $request->validated());

        return redirect()->back()->with('success', 'Купон обновлён');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $this->couponService->delete($coupon);

        return redirect()->back()->with('success', 'Купон удалён');
    }
}