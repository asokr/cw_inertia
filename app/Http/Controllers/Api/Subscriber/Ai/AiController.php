<?php

namespace App\Http\Controllers\Api\Subscriber\Ai;

use Illuminate\Http\Request;
use App\Http\Traits\ChatGptTrait;
use App\Http\Controllers\Controller;
use App\Http\Traits\FusionBrainTrait;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\SubscribersSubscriptions;

class AiController extends Controller
{
    use ChatGptTrait;
    use FusionBrainTrait;

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

        $limit = match ($request->for) {
            'feedbacks' => 'feedbacks_gpt_query',
            default => 'ai_text_query',
        };

        // Проверим лимит подписчика
        $subscriber_id = auth()->user()->subscriber->id;
        $userSubscription = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriber_id,
            'status' => 1
        ])->first();

        if (!$userSubscription->getMonthLimit($limit))
            return response()->json(["success" => false, "messages" => ["Вы исчерпали лимит запросов к ИИ по тарифу."]], 200);

        $type = $request->type ? "Ты $request->type" : 'Ты помощник по написанию статей.';

        $resp = $this->askToChatGpt($type, $request->prompt);

        if (!$resp) {
            return response()->json(["success" => false, "messages" => ["Ошибка в работе с ИИ"]], 200);
        }

        $userSubscription->minusMonthLimit($limit);

        return response()->json(["success" => true, "messages" => ["Ответ ИИ"], "data" => $resp], 200);
    }

    public function dialog(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'messages' => 'array|required',
            'messages.*' => 'required',
            'type' => '',
            'image' => ''
        ], [
            'messages.*.required' => 'Что-то не так, отправляется пустой запрос'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }


        if (isset($request->image) && !empty($request->image)) {

            $limit = 'ai_image_query';

            $subscriber_id = auth()->user()->subscriber->id;
            $userSubscription = SubscribersSubscriptions::where([
                'subscribers_id' => $subscriber_id,
                'status' => 1
            ])->first();

            if (!$userSubscription->getMonthLimit($limit))
                return response()->json(["success" => false, "messages" => ["Вы исчерпали лимит запросов к ИИ по тарифу."]], 200);

            $promt = $request->messages[count($request->messages) - 1]['content'];

            $vision = $this->visionDialog($promt, $request->image);

            if (!$vision) {
                return response()->json(["success" => false, "messages" => ["Ошибка в работе с ИИ"]], 200);
            }

            // После удачного запроса к АПИ, обновим лимит
            $userSubscription->minusMonthLimit($limit);


            return response()->json(["success" => true, "messages" => ["Ответ ИИ"], "data" => $vision], 200);
        }


        $limit = 'ai_text_query';

        $subscriber_id = auth()->user()->subscriber->id;
        $userSubscription = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriber_id,
            'status' => 1
        ])->first();

        if (!$userSubscription->getMonthLimit($limit))
            return response()->json(["success" => false, "messages" => ["Вы исчерпали лимит запросов к ИИ по тарифу."]], 200);

        $type = $request->type ? "Ты $request->type" : 'Ты копирайтер маркетолог. Пишешь описания товаров для маркетплейса.';

        $messages = $request->messages;
        array_unshift($messages, ['role' => 'system', 'content' => $type]);

        $resp = $this->dialogToChatGpt($messages);

        if (!$resp) {
            return response()->json(["success" => false, "messages" => ["Ошибка в работе с GPT"]], 200);
        }

        // После удачного запроса к АПИ, обновим лимит
        $userSubscription->minusMonthLimit($limit);

        return response()->json(["success" => true, "messages" => ["Ответ GPT"], "data" => $resp], 200);
    }

    public function imageGenerate(Request $request)
    {
        $request->merge([
            'prompt' => $request->input('prompt')
                ?? $request->input('image_prompt')
                ?? $request->input('message')
                ?? $request->input('text'),
        ]);

        $validator = Validator::make($request->all(), [
            'prompt' => 'required|min:10|max:1000',
            'size' => '',
            'style' => '',
            'quality' => '',
        ], [
            'prompt.required' => 'Не передан текст запроса для генерации изображения',
            'prompt.min' => 'Запрос для генерации изображения должен содержать минимум 10 символов',
            'prompt.max' => 'Запрос для генерации изображения превышает максимум 1000 символов',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $limit = 'ai_image_query';

        $subscriber_id = auth()->user()->subscriber->id;
        $userSubscription = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriber_id,
            'status' => 1
        ])->first();

        if (!$userSubscription->getMonthLimit($limit))
            return response()->json(["success" => false, "messages" => ["Вы исчерпали лимит запросов к ИИ по тарифу."]], 200);

        //gpt-image-1.5
        $params = array(
            'model' => 'dall-e-3',
            'prompt' => $request->prompt,
            'size' => "1024x1024"
        );


        $data = $this->dalleImageGenerate($params);

        if (!$data) {
            return response()->json(["success" => false, "messages" => ["Ошибка в работе с ИИ"]], 200);
        }

        if (isset($data['error'])) {
            if ($data['error']['error']['code'] == "content_policy_violation") {
                $error_msg = 'Запрос заблокирован фильтрами контента';
            } else {
                $error_msg = "Ошибка";
            }

            return response()->json(["success" => false, "messages" => [$error_msg]], 200);
        }

        // Kandinsky
        // $model_id = $this->get_model();
        // if ($model_id) {
        //     $uuid = $this->generate($request->prompt, $model_id);
        // } else {
        //     return response()->json(["success" => false, "messages" => ["Ошибка. Попробуйте позже."]], 200);
        // }

        // if ($uuid) {
        //     $images = $this->check_generation($uuid);
        // } else {
        //     return response()->json(["success" => false, "messages" => ["Ошибка. Попробуйте позже."]], 200);
        // }

        // if ($images) {
        //     $data = [
        //         'base64' => $images
        //     ];
        // } else {
        //     return response()->json(["success" => false, "messages" => ["Ошибка. Попробуйте позже."]], 200);
        // }

        // После удачного запроса к АПИ, обновим лимит
        $userSubscription->minusMonthLimit($limit);

        return response()->json(["success" => true, "messages" => ["Ответ ИИ"], "data" => $data], 200);
    }

    // На вход тип лимита
    // Возвращает текущий лимит пользовтеля по ИИ
    public function showCurrentAiLimit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $subscriber_id = auth()->user()->subscriber->id;
        $userSubscription = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriber_id,
            'status' => 1
        ])->first();

        $limit = $userSubscription->getMonthLimit($request->limit);

        if ($limit) {
            return response()->json(["success" => true, "messages" => ["Ответ GPT"], "data" => $limit], 200);
        } else {
            return response()->json(["success" => true, "messages" => ["Ответ GPT"], "data" => 0], 200);
        }
    }

    private function visionDialog($prompt, $image)
    {
        $data = array(
            [
                'role' => 'user',
                'content' => array(
                    [
                        "type" => "text",
                        "text" => $prompt
                    ],
                    [
                        "type" => "image_url",
                        "image_url" => [
                            "url" => $image
                        ]
                    ]
                )
            ]
        );

        $resp = $this->visionToChatGpt($data);

        if (!$resp) {
            return false;
        }

        return $resp;
    }
}
