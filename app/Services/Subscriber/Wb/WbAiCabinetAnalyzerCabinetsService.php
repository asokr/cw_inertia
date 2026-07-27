<?php

namespace App\Services\Subscriber\Wb;
use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WbAiCabinetAnalyzerCabinetsService
{
    public function index(Request $request)
    {
        $cabinets = WbCabinet::where('user_id', (int) $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'messages' => ['Список кабинетов AiCabinet Analyzer'],
            'data' => $cabinets,
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'apikey' => 'required|string|max:1024',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $exists = WbCabinet::where('user_id', (int) $request->user()->id)
            ->get()
            ->contains(static fn(WbCabinet $cabinet): bool => (string) $cabinet->apikey === (string) $request->apikey);

        if ($exists) {
            return response()->json([
                'success' => false,
                'messages' => ['Кабинет с таким API ключом уже добавлен.'],
            ], 200);
        }

        $cabinet = WbCabinet::create([
            'user_id' => (int) $request->user()->id,
            'name' => (string) $request->name,
            'apikey' => (string) $request->apikey,
        ]);

        return response()->json([
            'success' => true,
            'messages' => ['Кабинет AiCabinet Analyzer добавлен'],
            'data' => $cabinet,
        ], 200);
    }

    public function show(Request $request, string $id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|integer|exists:wb_cabinets,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $cabinet = WbCabinet::find($id);
        if (!$cabinet || (int) $cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Кабинет AiCabinet Analyzer'],
            'data' => $cabinet,
        ], 200);
    }

    public function update(Request $request, string $id)
    {
        $validator = Validator::make(array_merge($request->all(), ['id' => $id]), [
            'id' => 'required|integer|exists:wb_cabinets,id',
            'name' => 'required|string|max:255',
            'apikey' => 'required|string|max:1024',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $cabinet = WbCabinet::find($id);
        if (!$cabinet || (int) $cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        $exists = WbCabinet::where('user_id', (int) $request->user()->id)
            ->where('id', '<>', (int) $id)
            ->get()
            ->contains(static fn(WbCabinet $item): bool => (string) $item->apikey === (string) $request->apikey);

        if ($exists) {
            return response()->json([
                'success' => false,
                'messages' => ['Кабинет с таким API ключом уже добавлен.'],
            ], 200);
        }

        $cabinet->name = (string) $request->name;
        $cabinet->apikey = (string) $request->apikey;
        $cabinet->save();

        return response()->json([
            'success' => true,
            'messages' => ['Кабинет AiCabinet Analyzer обновлён'],
            'data' => $cabinet,
        ], 200);
    }

    public function destroy(Request $request, string $id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|integer|exists:wb_cabinets,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $cabinet = WbCabinet::find($id);
        if (!$cabinet || (int) $cabinet->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        $cabinet->delete();

        return response()->json([
            'success' => true,
            'messages' => ['Кабинет AiCabinet Analyzer удалён'],
        ], 200);
    }
}
