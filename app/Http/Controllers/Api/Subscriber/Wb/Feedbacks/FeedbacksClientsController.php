<?php

namespace App\Http\Controllers\Api\Subscriber\Wb\Feedbacks;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Traits\WBFeedbacksTrait;
use App\Http\Traits\SubscriptionsTrait;
use App\Models\Subscribers\Subscribers;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;

class FeedbacksClientsController extends Controller
{
    use WBFeedbacksTrait;
    use SubscriptionsTrait;

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $user_id = Auth::id();
        $subscriber_id = Subscribers::where('user_id', $user_id)->first()->id;
        $clients = FeedbacksClients::where('subscriber_id', $subscriber_id)->orderByDesc('id')->get();
        if (!$clients) {
            return response()->json(["success" => false, "messages" => ["Кабинетов нет"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Список кабинетов"], "data" => $clients], 200);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
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
        $checkApiKey = $this->parseApiResponse($this->apiGetFeedbacks($request->apikey));
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
            if (isset($subs->limits_plan['feedbacks_clients'])) {
                $plan_id = $subs->plan_id;
                $limits = $subs->limits_plan;
            }
        }

        if (isset($limits['feedbacks_clients'])) {
            // Если лимит кончился
            if ((int) $limits['feedbacks_clients'] == 0) {
                return response()->json(["success" => false, "messages" => ["Вы исчерпали лимит на количество кабинетов по тарифу."]], 200);
            } else {
                // Отнимем лимит
                $limits['feedbacks_clients']--;
                SubscribersSubscriptions::where([
                    'subscribers_id' => $subscriber->id,
                    'plan_id' => $plan_id,
                    'status' => 1
                ])
                    ->update([
                        'limits_plan' => $limits
                    ]);
            }
        }

        $client = FeedbacksClients::create([
            "subscriber_id" => $subscriber->id,
            "name" => $request->name,
            "brands" => !empty($request->brands) ? $request->brands : '',
            "apikey" => $request->apikey,
        ]);

        if (!$client) {
            return response()->json(["success" => false, "messages" => ["Не удалось добавить кабинет"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Кабинет добавлен"], "data" => $client], 200);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $validator = Validator::make(['client_id' => $id], [
            'client_id' => 'required|exists:subs_wb_feedbacks_clients,id'
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

        $subscriber_id = auth()->user()->subscriber->id;
        // Проверим, принадлежит-ли кабинет текущему юзеру
        $belongs = $client->subscriber_id == $subscriber_id;
        if (!$belongs) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Кабинет получен"], "data" => $client], 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            "name" => "required",
            'brands' => '',
            "apikey" => "required",
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($id);
        if (!$client) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

        $subscriber_id = auth()->user()->subscriber->id;
        $belongs = $client->subscriber_id == $subscriber_id;
        if (!$belongs) {
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);
        }

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
        $checkApiKey = $this->parseApiResponse($this->apiGetFeedbacks($request->apikey));
        if (!$checkApiKey['success'] && $checkApiKey['code'] == 401) {
            return response()->json(["success" => false, "messages" => ["Не удалось авторизоваться с указанным API ключом. Проверьте ключ."]], 200);
        }

        $client->name = $request->name;
        $client->apikey = $request->apikey;
        $client->brands = !empty($request->brands) ? $request->brands : '';
        $client->save();

        return response()->json(["success" => true, "messages" => ["Кабинет обновлён"], "data" => $client], 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
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

        $subscriber = auth()->user()->subscriber;
        $belongs = $client->subscriber_id == $subscriber->id;
        if (!$belongs)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        $client->delete();

        // Актуализируем лимит
        $this->syncLimits($subscriber->id, 'feedbacks_clients');

        return response()->json(["success" => true, "messages" => ["Кабинет удалён"]], 200);
    }


    public function updateBotStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "client_id" => "required|exists:subs_wb_feedbacks_clients,id",
            "bot_status" => "required",
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->client_id);
        if (!$client)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        $subscriber_id = auth()->user()->subscriber->id;
        $belongs = $client->subscriber_id == $subscriber_id;
        if (!$belongs)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        $client->bot_status = $request->bot_status;

        $client->save();

        return response()->json(["success" => true, "messages" => ["Статус автоответов изменён"], "data" => $client->bot_status], 200);
    }

    public function getBotStatus(Request $request)
    {

        $validator = Validator::make($request->all(), [
            "client_id" => "required|exists:subs_wb_feedbacks_clients,id"
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->client_id);
        if (!$client)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        $subscriber_id = auth()->user()->subscriber->id;
        $belongs = $client->subscriber_id == $subscriber_id;
        if (!$belongs)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);


        return response()->json(["success" => true, "messages" => ["Статус автоматических отзывов"], "data" => $client->bot_status], 200);
    }


    public function updateAiData(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "client_id" => "required|exists:subs_wb_feedbacks_clients,id",
            "status" => "required",
            "review_type" => '',
            "ratings" => "present|array",
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->client_id);
        if (!$client)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        $subscriber_id = auth()->user()->subscriber->id;
        $belongs = $client->subscriber_id == $subscriber_id;
        if (!$belongs)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);


        $client->ai_status = $request->status;
        $client->ai_ratings = $request->ratings;
        $client->review_type = $request->review_type;

        $client->save();

        return response()->json(["success" => true, "messages" => ["Данные автоответов изменены"]], 200);
    }

    public function getAiData(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "client_id" => "required|exists:subs_wb_feedbacks_clients,id"
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->client_id);
        if (!$client)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);

        $subscriber_id = auth()->user()->subscriber->id;
        $belongs = $client->subscriber_id == $subscriber_id;
        if (!$belongs)
            return response()->json(["success" => false, "messages" => ["Такого кабинета нет"]], 200);


        return response()->json([
            "success" => true,
            "messages" => ["Данные автоответов получены"],
            "data" => [
                'status' => $client->ai_status,
                'ratings' => $client->ai_ratings,
                'review_type' => $client->review_type
            ]
        ], 200);
    }
}
