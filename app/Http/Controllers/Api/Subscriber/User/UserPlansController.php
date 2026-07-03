<?php

namespace App\Http\Controllers\Api\Subscriber\User;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\SubscribersPlans;

class UserPlansController extends Controller
{
    public function availablePlans(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required',
        ], [
            'type.required' => 'Не найден тип тарифов'
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()->all()], 422);
        }

        $data = SubscribersPlans::select([
            'id',
            'description',
            'duration',
            'name',
            'price',
            'permissions',
            'limits_plan',
            'limits_month',
            'extra_limits_month'
        ])->where(['status' => 1, 'hidden' => 0])
        ->where(function($query) use ($request){
            $query->whereJsonContains('permissions', $request->type);
            return $query;
        })
        ->get();

        if (!$data) {
            return response()->json(["success" => false, "messages" => ['Что-то пошло не так']], 200);
        }

        return response()->json(["success" => true, "messages" => ["Доступные тарифы получены"], "data" => $data], 200);
    }
}
