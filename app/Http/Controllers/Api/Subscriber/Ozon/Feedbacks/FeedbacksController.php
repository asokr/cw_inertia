<?php

namespace App\Http\Controllers\Api\Subscriber\Ozon\Feedbacks;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Traits\ChatGptTrait;
use App\Http\Traits\OzonApiTrait;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\Oz\Feedbacks\FeedbacksClients;

class FeedbacksController extends Controller
{
    use OzonApiTrait;
    use ChatGptTrait;

    private const OZON_API_ERROR_HINTS = [
        'Не хватает прав для API ключа' => 'У API-ключа не хватает прав для работы с отзывами. Проверьте настройки ключа в личном кабинете Ozon.',
        'Не верный ключ API или ClientId' => 'Неверный API-ключ или Client ID. Проверьте данные в настройках кабинета.',
    ];

    private function ozonUserMessage(array $resp, string $scenario): string
    {
        $apiMessage = $resp['data']['message'] ?? null;

        if (is_string($apiMessage) && isset(self::OZON_API_ERROR_HINTS[$apiMessage])) {
            return self::OZON_API_ERROR_HINTS[$apiMessage];
        }

        return match ($scenario) {
            'reviews_list' => 'Не удалось загрузить отзывы с Ozon. Проверьте API-ключ и Client ID, затем попробуйте снова.',
            'products' => 'Не удалось получить данные о товарах для отзывов. Попробуйте позже.',
            'count' => 'Не удалось получить количество неотвеченных отзывов. Попробуйте позже.',
            default => 'Не удалось выполнить запрос к Ozon. Попробуйте позже.',
        };
    }

    public function getFeedbacksList(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required|exists:oz_feedbacks_clients,id',
            'last_id' => '',
        ], [
            'cabinet_id.exists' => 'Указаны не все данные',
            'cabinet_id.exists' => 'Такого кабинета не существует'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->cabinet_id);

        if (!$client) {
            return response()->json(['success' => false, 'messages' => ['Кабинет не найден']], 200);
        }

        if ($client->user_id != Auth::id())
            return response()->json(["success" => false, "messages" => ["Не хватает прав"]], 200);

        $data = [];

        $params = ['limit' => 50, 'sort_dir' => 'DESC'];
        $filters = ['status' => 'UNPROCESSED'];

        if ($request->last_id)
            $params['last_id'] = $request->last_id;

        $resp = $this->parseApiResponse($this->getReviewList($client->apikey, $client->client_id, $params, $filters), $client);

        if (!$resp['success']) {
            return response()->json([
                'success' => false,
                'messages' => [$this->ozonUserMessage($resp, 'reviews_list')],
            ], 200);
        }

        $data['last_id'] = '';
        if ($resp['data']['has_next'])
            $data['last_id'] = $resp['data']['last_id'];

        $reviews = [];
        foreach ($resp['data']['reviews'] as $review) {
            if (!$client->empty_answer) { //отвечать на пустые отзывы
                if (!empty($review['text'])) {
                    array_push($reviews,  $review);
                }
            } else {
                array_push($reviews,  $review);
            }
        }

        $sku_list = [];
        foreach ($reviews as $review) {
            $sku_list[] = $review["sku"];
        }

        $params = [
            "sku" => $sku_list
        ];

        $resp = $this->parseApiResponse($this->getProductInfo($client->apikey, $client->client_id, $params), $client);
        if (!$resp['success']) {
            return response()->json([
                'success' => false,
                'messages' => [$this->ozonUserMessage($resp, 'products')],
            ], 200);
        }

        $data['reviews'] = [];
        foreach ($reviews as $review) {
            foreach ($resp['data']['items'] as $product) {
                if ($review['sku'] == $product['sources'][0]['sku']) {
                    $date = Carbon::parse($review['published_at']);
                    $arr = [
                        'id' => $review['id'],
                        'product_name' => $product['name'],
                        'offer_id' => $product['offer_id'],
                        'rating' => $review['rating'],
                        "primary_image" => $product['primary_image'],
                        "text" => $review['text'],
                        "order_status" => $review['order_status'],
                        "published_at" => $date->setTimezone('Europe/Moscow')->format('d.m.Y H:i')
                    ];
                    array_push($data['reviews'], $arr);
                }
            }
        }

        return response()->json(["success" => true, "messages" => ["Список отзывов получен"], "data" => $data], 200);
    }


    public function answerFeedback(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required|exists:oz_feedbacks_clients,id',
            'id' => 'required',
            'text' => 'required',
        ], [
            'cabinet_id.exists' => 'Такого кабинета не существует'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->cabinet_id);

        if (!$client) {
            return response()->json(['success' => false, 'messages' => ['Кабинет не найден']], 200);
        }

        if ($client->user_id != Auth::id())
            return response()->json(["success" => false, "messages" => ["Не хватает прав"]], 200);

        $params = [
            'review_id' => $request->id,
            'text' => $request->text,
            'mark_review_as_processed' => true
        ];

        $resp = $this->parseApiResponse($this->reviewAnswer($client->apikey, $client->client_id, $params), $client);

        if (!$resp['success']) {
            return response()->json(["success" => false, "messages" => ['Не удалось отправить ответ. Попробуйте позже.']], 200);
        }

        return response()->json(["success" => true, "messages" => ["Ответ отправлен"]], 200);
    }

    public function countFeedbacks(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cabinet_id' => 'required|exists:oz_feedbacks_clients,id',
        ], [
            'cabinet_id.exists' => 'Такого кабинета не существует'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->cabinet_id);

        if (!$client) {
            return response()->json(['success' => false, 'messages' => ['Кабинет не найден']], 200);
        }

        if ($client->user_id != Auth::id())
            return response()->json(["success" => false, "messages" => ["Не хватает прав"]], 200);

        $resp = $this->parseApiResponse($this->сountUnanswered($client->apikey, $client->client_id), $client);

        if (!$resp['success']) {
            return response()->json([
                'success' => false,
                'messages' => [$this->ozonUserMessage($resp, 'count')],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Количество отзывов получено'],
            'data' => $resp['data']['unprocessed'],
        ], 200);
    }
}
