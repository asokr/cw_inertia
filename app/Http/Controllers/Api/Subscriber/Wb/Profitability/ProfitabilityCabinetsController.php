<?php

namespace App\Http\Controllers\Api\Subscriber\Wb\Profitability;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Traits\SubscriptionsTrait;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\Wb\Profitability\ProfitabilityCabinet;

class ProfitabilityCabinetsController extends Controller
{
    use SubscriptionsTrait;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user_id = Auth::id();
        $cabinets = ProfitabilityCabinet::where('user_id', $user_id)->orderByDesc('id')->get();
        if (!$cabinets) {
            return response()->json(["success" => false, "messages" => ["Кабинетов нет"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Список кабинетов"], "data" => $cabinets], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'apikey' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        // Проверим, есть ли уже такой кабинет по apikey
        $cabinets = ProfitabilityCabinet::all();
        $alreadyRegistered = false;
        foreach ($cabinets as $cabinet) {
            if ($request->apikey == $cabinet->apikey) {
                $alreadyRegistered = true;
                break;
            }
        }
        if ($alreadyRegistered) {
            return response()->json(["success" => false, "messages" => ["Кабинет с таким Api ключом уже есть в системе."]], 200);
        }

        $cabinet = ProfitabilityCabinet::create([
            "user_id" => auth()->user()->id,
            "name" => $request->name,
            "apikey" => $request->apikey,
        ]);

        if (!$cabinet) {
            return response()->json(["success" => false, "messages" => ["Не удалось добавить кабинет"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Кабинет добавлен"], "data" => $cabinet], 200);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|exists:wb_Profitability_cabinets,id'
        ], [
            'id.exists' => 'Такого кабинета не существует'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $cabinet = ProfitabilityCabinet::find($id);
        if (!$cabinet) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        if ($cabinet->user_id !== auth()->user()->id) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Кабинет получен"], "data" => $cabinet], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            "name" => "required",
            "apikey" => "required",
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $cabinet = ProfitabilityCabinet::find($id);
        if (!$cabinet) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }
        if ($cabinet->user_id !== auth()->user()->id) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }


        // Проверим, есть ли уже такой кабинет по apikey
        $cabinets = ProfitabilityCabinet::all();
        $alreadyRegistered = false;
        foreach ($cabinets as $item) {
            if ($id == $item->id)
                continue;
            if ($request->apikey == $item->apikey) {
                $alreadyRegistered = true;
                break;
            }
        }
        if ($alreadyRegistered) {
            return response()->json(["success" => false, "messages" => ["Кабинет с таким Api ключом уже есть в системе."]], 200);
        }


        $cabinet->name = $request->name;
        $cabinet->apikey = $request->apikey;
        $cabinet->save();

        return response()->json(["success" => true, "messages" => ["Кабинет обновлён"], "data" => $cabinet], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|numeric'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $cabinet = ProfitabilityCabinet::find($id);
        if (!$cabinet)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);


        // Проверим, принадлежит-ли кабинет текущему юзеру
        if ($cabinet->user_id !== auth()->user()->id) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        $cabinet->delete();

        return response()->json(["success" => true, "messages" => ["Кабинет удалён"]], 200);
    }
}
