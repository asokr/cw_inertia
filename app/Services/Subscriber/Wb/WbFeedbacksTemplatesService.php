<?php

namespace App\Services\Subscriber\Wb;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Subscribers\Subscribers;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksTemplates;

class WbFeedbacksTemplatesService
{
    /**
     * Display a listing of the resource.
     */
    public function showAll(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:subs_wb_feedbacks_clients,id'
        ], [
            'client_id.exists' => 'Такого кабинета не существует'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->client_id);
        $user_id = Auth::id();
        $subscriber_id = Subscribers::where('user_id', $user_id)->first()->id;
        $belongs = $client->subscriber_id == $subscriber_id;
        if (!$belongs) {
            return response()->json(["success" => false, "messages" => ["Ошибка доступа"]], 200);
        }

        $data = FeedbacksTemplates::select('id', 'text', 'rating')
            ->where('client_id', $request->client_id)
            ->orderBy('id', 'desc')
            ->get();

        if (!$data)
            return response()->json(["success" => false, "messages" => ["Ошибка сервера"]], 200);
        if (!$data->count())
            return response()->json(["success" => true, "messages" => [], 'data' => []], 200);



        return response()->json(["success" => true, "messages" => ["Список шаблонов для отзывов получен"], "data" => $data], 200);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:subs_wb_feedbacks_clients,id',
            'text' => 'required|max:1200|min:10',
            'minRating' => 'required|numeric|min:1|max:5|lte:maxRating',
            'maxRating' => 'required|numeric|min:1|max:5|gte:minRating',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->client_id);
        $user_id = Auth::id();
        $subscriber_id = Subscribers::where('user_id', $user_id)->first()->id;
        $belongs = $client->subscriber_id == $subscriber_id;
        if (!$belongs) {
            return response()->json(["success" => false, "messages" => ["Ошибка доступа"]], 200);
        }

        $model = FeedbacksTemplates::create([
            'client_id' => $request->client_id,
            'text' => $request->text,
            'rating' => [$request->minRating, $request->maxRating]
        ]);

        if (!$model) {
            return response()->json(["success" => false, "messages" => ["Ошибка при сохранении шаблона"]], 200);
        }

        $data = array(
            'id' => $model->id,
            'text' => $model->text,
        );

        return response()->json(["success" => true, "messages" => ["Шаблон добавлен"], "data" => $data], 200);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {

        $belongs = $this->belongs($id);
        if (!$belongs) {
            return response()->json(["success" => false, "messages" => ["Ошибка доступа"]], 200);
        }
        $validator = Validator::make($request->all(), [
            'text' => 'required|max:1200|min:10',
            'minRating' => 'required|numeric|min:1|max:5|lte:maxRating',
            'maxRating' => 'required|numeric|min:1|max:5|gte:minRating',
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $model = FeedbacksTemplates::where('id', $id)->first();

        if (!$model) {
            return response()->json(["success" => false, "messages" => ["Ошибка при обновлении шаблона"]], 200);
        }

        $model->text = $request->text;
        $model->rating =  [$request->minRating, $request->maxRating];
        $model->save();

        return response()->json(["success" => true, "messages" => ["Шаблон обновлён"]], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $belongs = $this->belongs($id);
        if (!$belongs) {
            return response()->json(["success" => false, "messages" => ["Ошибка доступа"]], 200);
        }

        $model = FeedbacksTemplates::destroy($id);

        if (!$model) {
            return response()->json(["success" => false, "messages" => ["Ошибка при удалении шаблона"]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Шаблон удалён"]], 200);
    }

    private function belongs($id)
    {
        $model = FeedbacksTemplates::find($id);
        if (!$model) {
            return false;
        }

        $client = FeedbacksClients::find($model->client_id);
        $user_id = Auth::id();
        $subscriber_id = Subscribers::where('user_id', $user_id)->first()->id;

        $belongs = $client->subscriber_id == $subscriber_id;

        if (!$belongs) {
            return false;
        }

        return true;
    }

}

