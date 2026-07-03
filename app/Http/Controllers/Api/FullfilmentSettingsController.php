<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Models\FullfilmentPrices;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;

class FullfilmentSettingsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $data = FullfilmentPrices::select([
            'id',
            'city',
            'warehouses',
            'marketplaces',
            'our_services',
            'services',
        ])->get();

        return response()->json(["success" => true, "messages" => ["Цены получены"], "data" => $data], 200);
    }


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $data = FullfilmentPrices::where('id', $id)
        ->select([
            'city',
            'warehouses',
            'marketplaces',
            'our_services',
            'services',
        ])->get();

        return response()->json(["success" => true, "messages" => ["Цены получены"], "data" => $data], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'warehouses' => 'required|array',
            'marketplaces' => 'required|array',
            'our_services' => 'required|array',
            'services' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()->all()], 422);
        }

        $model = FullfilmentPrices::find($id);

        if (!$model) {
            return response()->json(["success" => false, "messages" => ['Такого прайса нет']], 200);
        }

        $model->warehouses = $request->warehouses;
        $model->marketplaces = $request->marketplaces;
        $model->our_services = $request->our_services;
        $model->services = $request->services;
        $model->save();

        return response()->json(["success" => true, "messages" => ["Прайс обновлён"]], 200);

    }

}
