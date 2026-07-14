<?php

namespace App\Services\Subscriber\Wb;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Traits\SubscriptionsTrait;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Repricer\RepricerLogs;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;

class RepricerCabinetsService
{
    use SubscriptionsTrait;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user_id = Auth::id();
        $clients = RepricerCabinets::where('user_id', $user_id)->orderByDesc('id')->get();
        if (!$clients) {
            return response()->json(["success" => false, "messages" => ["Кабинетов нет"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Список кабинетов"], "data" => $clients], 200);
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
        $clients = RepricerCabinets::all();
        $alreadyRegistered = false;
        foreach ($clients as $client) {
            if ($request->apikey == $client->apikey) {
                $alreadyRegistered = true;
                break;
            }
        }
        if ($alreadyRegistered) {
            return response()->json(["success" => false, "messages" => ["Кабинет с таким Api ключом уже есть в системе."]], 200);
        }

        $client = RepricerCabinets::create([
            "user_id" => auth()->user()->id,
            "name" => $request->name,
            "apikey" => $request->apikey,
        ]);

        if (!$client) {
            return response()->json(["success" => false, "messages" => ["Не удалось добавить кабинет"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Кабинет добавлен"], "data" => $client], 200);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|exists:wb_repricer_cabinets,id'
        ], [
            'id.exists' => 'Такого кабинета не существует'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = RepricerCabinets::find($id);
        if (!$client) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        // Проверим, принадлежит-ли кабинет текущему юзеру
        $belongs = $client->user_id == auth()->user()->id;
        if (!$belongs) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Кабинет получен"], "data" => $client], 200);
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

        $cabinet = RepricerCabinets::find($id);
        if (!$cabinet) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        $oldApiKey = $cabinet->apikey;

        // Проверим, принадлежит-ли кабинет текущему юзеру
        $belongs = $cabinet->user_id == auth()->user()->id;
        if (!$belongs) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        // Проверим, есть ли уже такой кабинет по apikey
        $cabinets = RepricerCabinets::all();
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
        if ($oldApiKey !== $request->apikey) {
            $cabinet->error_code = null;
            $cabinet->error_message = null;
        }
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

        $cabinet = RepricerCabinets::find($id);
        if (!$cabinet)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);


        // Проверим, принадлежит-ли кабинет текущему юзеру
        $belongs = $cabinet->user_id == auth()->user()->id;
        if (!$belongs) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        $cabinet->delete();


        return response()->json(["success" => true, "messages" => ["Кабинет удалён"]], 200);
    }


    public function getLogs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required|numeric',
            'nmID' => 'required|numeric',
            'strategy' => ''
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $cabinet = RepricerCabinets::find($request->cabinet_id);
        if (!$cabinet)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        // Проверим, принадлежит-ли кабинет текущему юзеру
        $belongs = $cabinet->user_id == auth()->user()->id;
        if (!$belongs) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        $data = RepricerLogs::select([
            'nmID',
            'message',
            'type',
            'created_at'
        ])->where(['cabinet_id' => $request->cabinet_id, 'strategy' => $request->strategy, 'nmID' => $request->nmID])->limit(50)->orderBy('id', 'desc')->get();

        return response()->json(["success" => true, "messages" => ["Логи работы репрайсера"], "data" => $data], 200);
    }
}
