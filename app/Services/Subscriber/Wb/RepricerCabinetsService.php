<?php

namespace App\Services\Subscriber\Wb;

use App\Http\Traits\SubscriptionsTrait;
use App\Models\Subscribers\Wb\Repricer\RepricerLogs;
use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class RepricerCabinetsService
{
    use SubscriptionsTrait;

    public function index()
    {
        $user_id = Auth::id();
        $clients = WbCabinet::where('user_id', $user_id)->orderByDesc('id')->get();
        if (! $clients) {
            return response()->json(['success' => false, 'messages' => ['Кабинетов нет']], 200);
        }

        return response()->json(['success' => true, 'messages' => ['Список кабинетов'], 'data' => $clients], 200);
    }

    public function store(Request $request)
    {
        return response()->json([
            'success' => false,
            'messages' => ['Создавайте кабинеты на странице «Общие кабинеты».'],
        ], 200);
    }

    public function show(string $id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|exists:wb_cabinets,id',
        ], [
            'id.exists' => 'Такого кабинета не существует',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $client = WbCabinet::find($id);
        if (! $client) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        $belongs = $client->user_id == auth()->user()->id;
        if (! $belongs) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        return response()->json(['success' => true, 'messages' => ['Кабинет получен'], 'data' => $client], 200);
    }

    public function update(Request $request, string $id)
    {
        return response()->json([
            'success' => false,
            'messages' => ['Управляйте кабинетами на странице «Общие кабинеты».'],
        ], 200);
    }

    public function destroy(string $id)
    {
        return response()->json([
            'success' => false,
            'messages' => ['Управляйте кабинетами на странице «Общие кабинеты».'],
        ], 200);
    }

    public function getLogs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required|numeric',
            'nmID' => 'required|numeric',
            'strategy' => '',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'messages' => $validator->errors()->all()], 200);
        }

        $cabinet = WbCabinet::find($request->cabinet_id);
        if (! $cabinet) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        $belongs = $cabinet->user_id == auth()->user()->id;
        if (! $belongs) {
            return response()->json(['success' => false, 'messages' => ['Такого кабинета нет']], 200);
        }

        $data = RepricerLogs::select([
            'nmID',
            'message',
            'type',
            'created_at',
        ])->where([
            'cabinet_id' => $request->cabinet_id,
            'strategy' => $request->strategy,
            'nmID' => $request->nmID,
        ])->limit(50)->orderBy('id', 'desc')->get();

        return response()->json(['success' => true, 'messages' => ['Логи работы репрайсера'], 'data' => $data], 200);
    }
}
