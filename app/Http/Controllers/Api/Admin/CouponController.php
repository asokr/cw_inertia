<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Coupon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class CouponController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $data = Coupon::all();

        return response()->json(["success" => true, "messages" => ["Данные получены"], "data" => $data], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required',
            'limit' => '',
            'type' => 'in:fixed,percentage,registration',
            'value' => 'required', //Если type = registration, value = id тарифа, который даётся бесплатно
            'start_date' => '',
            'end_date' => '',

        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        Coupon::create($validator->validated());

        return response()->json(["success" => true, "messages" => 'Купон добавлен'], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required',
            'limit' => '',
            'type' => 'in:fixed,percentage,registration',
            'value' => 'required',
            'start_date' => '',
            'end_date' => '',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $model = Coupon::find($id);
        if (!$model)
            return response()->json(["success" => false, "messages" => ["Купон не найден"]], 200);

        $model->update($validator->validated());

        return response()->json(["success" => true, "messages" => 'Купон обновлен'], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $model = Coupon::find($id);
        if (!$model)
            return response()->json(["success" => false, "messages" => ["Купон не найден"]], 200);

        $model->delete();

        return response()->json(["success" => true, "messages" => 'Купон удалён'], 200);
    }
}
