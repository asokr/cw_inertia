<?php

namespace App\Http\Controllers\Api\Admin\wb;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;

class FeedbacksController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $data = FeedbacksClients::all();

        if (!$data) {
             return response()->json(["success" => false, "messages" => ["Ошибка при получении данных"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Данные логирования получены"], "data" => $data], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric',
            'subscriber_id' => 'required|exists:subscribers,id',
        ]);
        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->id);
        if (!$client)
            return response()->json(["success" => false, "messages" => ["Такого клиента нет"]], 200);


        $belongs = $client->subscriber_id == $request->subscriber_id;
        if (!$belongs)
            return response()->json(["success" => false, "messages" => ["Такого клиента нет"]], 200);

        $client->delete();

        // Актуализируем лимит
        $userSubscriptions = SubscribersSubscriptions::where([
            'subscribers_id' => $request->subscriber_id,
            'status' => 1
        ])->get();
        foreach ($userSubscriptions as $subs) {
            if (isset ($subs->limits_plan['feedbacks_clients'])) {
                $plan_id = $subs->plan_id;
                $limits = $subs->limits_plan;
            }
        }

        // добавим лимит
        $limits['feedbacks_clients']++;
        SubscribersSubscriptions::where([
            'subscribers_id' => $request->subscriber_id,
            'plan_id' => $plan_id,
            'status' => 1
        ])
            ->update([
                'limits_plan' => $limits
            ]);

        return response()->json(["success" => true, "messages" => ["Клиент удалён"]], 200);
    }
}
