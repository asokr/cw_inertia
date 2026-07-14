<?php

namespace App\Services\Subscriber\Oz;

use Illuminate\Http\Request;
use App\Http\Traits\OzonApiTrait;
use Illuminate\Support\Facades\Auth;
use App\Http\Traits\SubscriptionsTrait;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Support\ToolLimits;
use App\Models\Subscribers\Oz\Feedbacks\FeedbacksClients;

class OzFeedbacksClientsService
{

    use OzonApiTrait;
    use SubscriptionsTrait;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user_id = Auth::id();
        $clients = FeedbacksClients::where('user_id', $user_id)->orderByDesc('id')->get();
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
            "name" => "required",
            "apikey" => "required",
            'empty_answer' => '',
            'signature' => '',
            'client_id' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        // Проверим, есть ли уже такой кабинет по apikey
        $clients = FeedbacksClients::all();
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

        // Проверим, авторизуется ли API ключ.
        $checkApiKey = $this->parseApiResponse($this->getReviewList($request->apikey, $request->client_id));
        if (!$checkApiKey['success']) {
            if ($checkApiKey['code'] == 401) {
                return response()->json(["success" => false, "messages" => ["Не удалось авторизоваться с указанным API ключом. Проверьте ключ."]], 200);
            }
        }

        $subscriber = auth()->user()->subscriber;

        // Проверим лимит подписчика
        $userSubscriptions = SubscribersSubscriptions::where([
            'subscribers_id' => $subscriber->id,
            'status' => 1
        ])->get();
        foreach ($userSubscriptions as $subs) {
            if (isset($subs->limits_plan['oz_feedbacks_clients'])) {
                $plan_id = $subs->plan_id;
                $limits = $subs->limits_plan;
            }
        }

        if (isset($limits['oz_feedbacks_clients']) && ! ToolLimits::canUsePlanLimit(auth()->user(), $limits, 'oz_feedbacks_clients')) {
            return response()->json(["success" => false, "messages" => ["Вы исчерпали лимит на количество кабинетов по тарифу."]], 200);
        }

        $updatedLimits = isset($limits['oz_feedbacks_clients'])
            ? ToolLimits::applyPlanLimitConsumption(auth()->user(), $limits, 'oz_feedbacks_clients')
            : null;

        if ($updatedLimits !== null) {
            SubscribersSubscriptions::where([
                'subscribers_id' => $subscriber->id,
                'plan_id' => $plan_id,
                'status' => 1,
            ])->update([
                'limits_plan' => $updatedLimits,
            ]);
        }

        $client = FeedbacksClients::create([
            "user_id" => Auth::id(),
            "name" => $request->name,
            "apikey" => $request->apikey,
            "client_id" => $request->client_id,
            'empty_answer' => $request->empty_answer ?? 0,
            'signature' => $request->signature ?? NULL,
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
        $validator = Validator::make(['client_id' => $id], [
            'client_id' => 'required|exists:oz_feedbacks_clients,id'
        ], [
            'client_id.exists' => 'Такого кабинета не существует'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($id);
        if (!$client) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        if ($client->user_id != Auth::id())
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

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
            'empty_answer' => '',
            'signature' => '',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($id);
        if (!$client) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        if ($client->user_id != Auth::id())
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        // Проверим, есть ли уже такой кабинет по apikey
        $clients = FeedbacksClients::all();
        $alreadyRegistered = false;
        foreach ($clients as $item) {
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

        // Проверим, авторизуется ли API ключ.
        $checkApiKey = $this->parseApiResponse($this->getReviewList($request->apikey, $request->client_id));
        if (!$checkApiKey['success']) {
            if ($checkApiKey['code'] == 401) {
                return response()->json(["success" => false, "messages" => ["Не удалось авторизоваться с указанным API ключом. Проверьте ключ."]], 200);
            }
        }

        $client->name = $request->name;
        $client->apikey = $request->apikey;
        $client->signature = $request->signature;
        $client->empty_answer = $request->empty_answer;
        $client->save();

        return response()->json(["success" => true, "messages" => ["Кабинет обновлён"], "data" => $client], 200);
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

        $client = FeedbacksClients::find($id);
        if (!$client)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        if ($client->user_id != Auth::id())
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        $client->delete();

        // Актуализируем лимит
        $subscriber = auth()->user()->subscriber;
        $this->syncLimits($subscriber->id, 'oz_feedbacks_clients');

        return response()->json(["success" => true, "messages" => ["Кабинет удалён"]], 200);
    }

    public function updateBotStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "cabinet_id" => "required|exists:oz_feedbacks_clients,id",
            "bot_status" => "required",
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->cabinet_id);
        if (!$client)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        if ($client->user_id != Auth::id())
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        $client->bot_status = $request->bot_status;

        $client->save();

        return response()->json(["success" => true, "messages" => ["Статус автоответов изменён"], "data" => $client->bot_status], 200);
    }

    public function getBotStatus(Request $request)
    {

        $validator = Validator::make($request->all(), [
            "cabinet_id" => "required|exists:oz_feedbacks_clients,id"
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->cabinet_id);
        if (!$client)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        if ($client->user_id != Auth::id())
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);


        return response()->json(["success" => true, "messages" => ["Статус автоматических отзывов"], "data" => $client->bot_status], 200);
    }


    public function updateAiData(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "cabinet_id" => "required|exists:oz_feedbacks_clients,id",
            "status" => "required",
            "empty_answer" => "boolean",
            "signature" => "",
            "ratings" => "present|array",
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->cabinet_id);
        if (!$client)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        if ($client->user_id != Auth::id())
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        $client->ai_status = $request->status;
        $client->ai_ratings = $request->ratings;
        $client->empty_answer = $request->empty_answer;
        $client->signature = $request->signature;

        $client->save();

        return response()->json(["success" => true, "messages" => ["Данные автоответов изменены"]], 200);
    }

    public function getAiData(Request $request, $cabinet_id)
    {
        $validator = Validator::make(['cabinet_id' => $cabinet_id], [
            "cabinet_id" => "required|exists:oz_feedbacks_clients,id"
        ], [
            'cabinet_id.exists' => 'Такого кабинета нет',
            'cabinet_id.required' => 'Не хватает данных',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($cabinet_id);
        if (!$client)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        if ($client->user_id != Auth::id())
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);


        return response()->json([
            "success" => true,
            "messages" => ["Данные автоответов получены"],
            "data" => [
                "status" => $client->ai_status,
                "empty_answer" => $client->empty_answer,
                "signature" => $client->signature,
                "ratings" => $client->ai_ratings,
            ]
        ], 200);
    }
}