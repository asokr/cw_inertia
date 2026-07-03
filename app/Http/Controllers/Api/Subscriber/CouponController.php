<?php

namespace App\Http\Controllers\Api\Subscriber;

use App\Services\CouponService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class CouponController extends Controller
{

    public function checkCoupon(Request $request, CouponService $couponService)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, "messages" => $validator->errors()->all()], 200);
        }

        try {
            $coupon = $couponService->validateCoupon($request->code);
            $coupon->plan = $coupon->getCouponPlan();

            return response()->json(["success" => true, "messages" => 'Купон действителен', "data" => $coupon], 200);
        } catch (\Throwable $th) {
            return response()->json(["success" => false, "messages" => [$th->getMessage()]], 200);
        }
    }

}
