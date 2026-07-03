<?php

namespace App\Http\Controllers\Api\Admin\services\aicabinetanalyzer;

use App\Http\Controllers\Controller;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerTemplate;
use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminAiCabinetAnalyzerController extends Controller
{
    public function cabinetsList()
    {
        $data = AiCabinetAnalyzerCabinet::with([
            'user' => function ($query) {
                $query->select('id', 'name', 'email')->with([
                    'subscriber' => function ($q) {
                        $q->select('id', 'user_id');
                    }
                ]);
            }
        ])
            ->select(['id', 'user_id', 'name', 'created_at', 'updated_at'])
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($cabinet) {
                $user = $cabinet->user;
                $cabinet->owner = $user
                    ? ($user->email ?: $user->name ?: 'User #' . $user->id)
                    : 'Unknown (user_id: ' . $cabinet->user_id . ')';
                $cabinet->subscriber_id = ($user && $user->subscriber) ? $user->subscriber->id : null;
                return $cabinet;
            });

        return response()->json([
            "success" => true,
            "messages" => ["Данные получены"],
            "data" => $data
        ], 200);
    }

    public function templatesList()
    {
        $data = AiCabinetAnalyzerTemplate::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            "success" => true,
            "messages" => ["Данные получены"],
            "data" => $data
        ], 200);
    }

    public function storeTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'system_prompt' => 'required|string',
            'sort_order' => 'nullable|integer|min:0|max:10000',
            'is_active' => 'nullable|boolean',
            'response_format' => 'nullable|in:json,markdown',
        ], [
            'name.required' => 'Укажите название промпта',
            'system_prompt.required' => 'Укажите текст промпта (system_prompt)',
        ]);

        if ($validator->fails()) {
            return response()->json([
                "success" => false,
                "messages" => $validator->errors()->all()
            ], 200);
        }

        $template = AiCabinetAnalyzerTemplate::create([
            'name' => (string) $request->input('name'),
            'description' => $request->input('description') ? (string) $request->input('description') : null,
            'system_prompt' => (string) $request->input('system_prompt'),
            'sort_order' => (int) ($request->input('sort_order', 100)),
            'is_active' => (bool) ($request->has('is_active') ? $request->boolean('is_active') : true),
            'response_format' => (string) ($request->input('response_format', 'json')),
        ]);

        return response()->json([
            "success" => true,
            "messages" => ["Промпт добавлен"],
            "data" => $template
        ], 200);
    }

    public function updateTemplate(Request $request, string $id)
    {
        $validator = Validator::make(array_merge($request->all(), ['id' => $id]), [
            'id' => 'required|integer|exists:wb_ai_cabinet_analyzer_templates,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'system_prompt' => 'required|string',
            'sort_order' => 'nullable|integer|min:0|max:10000',
            'is_active' => 'nullable|boolean',
            'response_format' => 'nullable|in:json,markdown',
        ], [
            'name.required' => 'Укажите название промпта',
            'system_prompt.required' => 'Укажите текст промпта (system_prompt)',
            'id.exists' => 'Промпт не найден',
        ]);

        if ($validator->fails()) {
            return response()->json([
                "success" => false,
                "messages" => $validator->errors()->all()
            ], 200);
        }

        $template = AiCabinetAnalyzerTemplate::find((int) $id);
        if (!$template) {
            return response()->json([
                "success" => false,
                "messages" => ["Промпт не найден"]
            ], 200);
        }

        $template->name = (string) $request->input('name');
        $template->description = $request->input('description') ? (string) $request->input('description') : null;
        $template->system_prompt = (string) $request->input('system_prompt');
        $template->sort_order = (int) ($request->input('sort_order', $template->sort_order ?? 100));
        if ($request->has('is_active')) {
            $template->is_active = (bool) $request->boolean('is_active');
        }
        if ($request->has('response_format')) {
            $template->response_format = (string) $request->input('response_format');
        }
        $template->save();

        return response()->json([
            "success" => true,
            "messages" => ["Промпт обновлён"],
            "data" => $template
        ], 200);
    }

    public function destroyTemplate(string $id)
    {
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|integer|exists:wb_ai_cabinet_analyzer_templates,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                "success" => false,
                "messages" => $validator->errors()->all()
            ], 200);
        }

        $template = AiCabinetAnalyzerTemplate::find((int) $id);
        if (!$template) {
            return response()->json([
                "success" => false,
                "messages" => ["Промпт не найден"]
            ], 200);
        }

        $template->delete();

        return response()->json([
            "success" => true,
            "messages" => ["Промпт удалён"]
        ], 200);
    }
}
