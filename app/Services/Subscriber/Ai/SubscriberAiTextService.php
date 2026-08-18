<?php

namespace App\Services\Subscriber\Ai;

use App\Http\Traits\ChatGptTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SubscriberAiTextService
{
    use ChatGptTrait;

    public function ask(Request $request)
    {
        $request->merge([
            'prompt' => $request->input('prompt')
                ?? $request->input('image_prompt')
                ?? $request->input('message')
                ?? $request->input('text'),
        ]);

        $validator = Validator::make($request->all(), [
            'prompt' => 'required|min:10|max:4000',
            'type' => '',
            'for' => ''
        ], [
            'prompt.required' => 'Не передан текст запроса к ИИ',
            'prompt.min' => 'Запрос должен составлять минимум 10 символов',
            'prompt.max' => 'Ваш запрос превысил максимум в 4000 символов',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $type = $request->type ? "Ты $request->type" : 'Ты помощник по написанию статей.';

        $resp = $this->askToChatGpt($type, $request->prompt);

        if (!$resp) {
            return response()->json(["success" => false, "messages" => ["Ошибка в работе с ИИ"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Ответ ИИ"], "data" => $resp], 200);
    }
}
