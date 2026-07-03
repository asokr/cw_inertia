<?php

namespace App\Http\Controllers\Api\Admin\subscribers;

use App\Models\ExtraLimits;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;


class ExtraLimitsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $data = ExtraLimits::orderBy('order')->get()->toArray();



        return response()->json(["success" => true, "messages" => ["Список лимитов"], 'data' => $data], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'price' => 'required|numeric',
            'limit_name' => 'required|string',
            'quantity' => 'required|numeric',
            'order' => 'numeric',
        ], [
            'required' => 'Не все данные указаны',
            'price.integer' => 'Указывайте цену целым числом',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $extraLimits = ExtraLimits::create([
            'price' => $request->price,
            'limit_name' => $request->limit_name,
            'quantity' => $request->quantity,
            'order' => $request->order || 0,
        ]);

        if (!$extraLimits)
            return response()->json(["success" => false, "messages" => ['Ошибка при добавлении дополнительных лимитов']], 200);


        return response()->json(["success" => true, "messages" => ["Успешно"]], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'price' => 'required|numeric',
            'limit_name' => 'required|string',
            'quantity' => 'required|numeric',
            'order' => 'numeric',
        ], [
            'required' => 'Не все данные указаны',
            'price.numeric' => 'Указывайте цену целым числом',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $extraLimits = ExtraLimits::where('id', $id)
            ->update([
                'price' => $request->price,
                'limit_name' => $request->limit_name,
                'quantity' => $request->quantity,
                'order' => $request->order,
            ]);

        if (!$extraLimits)
            return response()->json(["success" => false, "messages" => ['Ошибка при обновлении дополнительного лимита']], 200);


        return response()->json(["success" => true, "messages" => ["Успешно"]], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $extraLimits = ExtraLimits::where('id', $id)
            ->delete();

        if (!$extraLimits)
            return response()->json(["success" => false, "messages" => ['Ошибка при удалении дополнительного лимита']], 200);

        return response()->json(["success" => true, "messages" => ["Успешно"]], 200);
    }
}
