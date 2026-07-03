<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Services\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function check(Request $request, CouponService $couponService): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        try {
            $coupon = $couponService->validateCoupon($request->input('code'));
            $coupon->plan = $coupon->getCouponPlan();

            return response()->json([
                'success' => true,
                'message' => 'Купон действителен',
                'data' => $coupon,
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                'success' => false,
                'message' => $th->getMessage(),
            ], 422);
        }
    }
}