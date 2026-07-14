<?php

namespace App\Services\Subscriber\Wb;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Traits\WBApiTrait;
use App\Http\Traits\WBFeedbacksTrait;
use Illuminate\Support\Facades\Validator;
use App\Models\Subscribers\Wb\Feedbacks\Review;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;

class WbFeedbacksService
{

    use WBFeedbacksTrait;
    use WBApiTrait;

    public function getFeedbacksList(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:subs_wb_feedbacks_clients,id',
            'take' => '',
            'skip' => 'required',
        ], [
            'client_id.exists' => 'Такого кабинета не существует'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }


        $client = FeedbacksClients::find($request->client_id);

        if (!$client)
            return response()->json(['success' => false, 'messages' => ['Ошибка при получении клиента']], 200);

        $subscriber = auth()->user()->subscriber;
        $belongs = $client->subscriber_id == $subscriber->id;
        if (!$belongs)
            return response()->json(["success" => false, "messages" => ["Не хватает прав"]], 200);

        $count = $this->parseApiResponse($this->apiFeedbacksCountUnanswered($client->apikey));

        if ($count['success']) {
            $take = $count['data']['countUnanswered'];
        } else if ($count["code"] == 401) {
            return response()->json(["success" => false, "messages" => ["Не удаётся авторизоваться с указаным API ключом"]], 200);
        } else {
            $take = 100;
        }

        $params = array(
            'take' => $take,
            'skip' => $request->skip,
        );

        $data = $this->parseApiResponse($this->apiGetFeedbacks($client->apikey, $params));

        if (!$data['success']) {
            $message = $this->extractWbErrorMessage($data['data']);
            return response()->json(["success" => false, "messages" => [$message]], 200);
        }

        // Тут WB при ответе может давать какие-то ошибки, согласно документации
        // Давайте здесь их и проверим
        if (is_array($data['data']) && !empty($data['data']['error'])) {
            return response()->json(["success" => false, "messages" => [$data['data']['errorText']]], 200);
        }

        $data = $data['data']['data'];
        $feedbacks = array();
        foreach ($data['feedbacks'] as $item) {
            // Проверим, есть ли ограничения по бренду, и если есть, отфильтруем
            if (!empty($client->brands)) {
                $allow = false;
                $allowed_brands = explode(',', $client->brands);
                foreach ($allowed_brands as $value) {
                    $feedback_brand = strtolower(trim($item['productDetails']['brandName']));
                    $client_brand = strtolower(trim($value));
                    if ($feedback_brand == $client_brand) {
                        $allow = true;
                    }
                }
                if (!$allow) {
                    continue;
                }
            }
            $feedbacks[] = array(
                'id' => $item['id'],
                'name' => $item['userName'],
                'answer' => $item['answer'],
                'text' => $item['text'],
                'pros' => $item['pros'],
                'cons' => $item['cons'],
                'createdDate' => Carbon::parse($item['createdDate'])->format('d.m.Y H:i:s'),
                'photoLinks' => $item['photoLinks'],
                'productValuation' => $item['productValuation'],
                'productDetails' => array(
                    'brandName' => $item['productDetails']['brandName'],
                    'nmId' => $item['productDetails']['nmId'],
                    'productName' => $item['productDetails']['productName'],
                    'supplierArticle' => $item['productDetails']['supplierArticle'],
                    'photo' => $this->getProductImages(1, $item['productDetails']['nmId'])[0]['imageS'],
                ),
            );
        }

        $data['feedbacks'] = $feedbacks;

        return response()->json(["success" => true, "messages" => ["Список отзывов получен"], "data" => $data], 200);
    }

    public function sendFeedbackToWb(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:subs_wb_feedbacks_clients,id',
            'id' => 'required|',
            'text' => 'required',
        ], [
            'client_id.exists' => 'Такого кабинета не существует'
        ]);

        if ($validator->fails()) {
            return response()->json(["success" => false, "messages" => $validator->errors()->all()], 200);
        }

        $client = FeedbacksClients::find($request->client_id);

        if (!$client)
            return response()->json(['success' => false, 'messages' => ['Ошибка при получении клиента']], 200);

        $subscriber = auth()->user()->subscriber;
        $belongs = $client->subscriber_id == $subscriber->id;
        if (!$belongs)
            return response()->json(["success" => false, "messages" => ["Не хватает прав"]], 200);

        $params = array(
            'id' => $request->id,
            'text' => $request->text,
        );

        $data = $this->parseApiResponse($this->apiPostAnswer($client->apikey, $params));

        if (!$data['success']) {
            $message = $this->extractWbErrorMessage($data['data']);
            return response()->json(["success" => false, "messages" => [$message]], 200);
        }

        return response()->json(["success" => true, "messages" => ["Ответ отправлен"]], 200);
    }

    // public function collectCons(Request $request)
    // {

    //     $cabinetId = 35;


    //     $consTexts = Review::where('cabinet_id', $cabinetId)
    //         ->whereNotNull('cons') // Проверяем, что cons не null
    //         ->where('cons', '!=', '') // Проверяем, что cons не пустая строка
    //         ->pluck('cons') // Извлекаем только поле cons
    //         ->toArray(); // Преобразуем результат в массив

    //     return $consTexts;
    // }

    private function extractWbErrorMessage($payload): string
    {
        if (is_array($payload)) {
            if (!empty($payload['errorText'])) {
                return (string) $payload['errorText'];
            }

            if (!empty($payload['error'])) {
                return (string) $payload['error'];
            }

            return 'Ошибка при обращении к API Wildberries';
        }

        return is_string($payload) && $payload !== ''
            ? $payload
            : 'Ошибка при обращении к API Wildberries';
    }
}
