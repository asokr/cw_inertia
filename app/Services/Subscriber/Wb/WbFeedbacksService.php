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
            'skip' => 'nullable|integer|min:0',
            'nmId' => ['nullable', 'integer', 'min:1'],
            'ratings' => ['nullable', 'array'],
            'ratings.*' => ['integer', 'min:1', 'max:5'],
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

        $nmId = $request->filled('nmId') ? (int) $request->input('nmId') : null;
        $ratings = collect($request->input('ratings', []))
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v >= 1 && $v <= 5)
            ->unique()
            ->values()
            ->all();

        $brandList = $this->parseCabinetBrands($client->brands);
        $brandFilterActive = $brandList !== [];

        $fetched = $this->fetchAllUnansweredFeedbacks($client->apikey, $nmId);

        if (! $fetched['success']) {
            return response()->json([
                'success' => false,
                'messages' => [$fetched['message'] ?? 'Не удалось загрузить отзывы'],
            ], 200);
        }

        $rawFeedbacks = $fetched['feedbacks'];
        $feedbacks = [];
        $skippedByBrand = 0;
        $skippedByRating = 0;

        foreach ($rawFeedbacks as $item) {
            $brandName = (string) ($item['productDetails']['brandName'] ?? '');

            // Бренды из настроек кабинета (добавление/редактирование) — post-filter WB ответа.
            if ($brandFilterActive && ! $this->brandAllowed($brandName, $brandList)) {
                $skippedByBrand++;
                continue;
            }

            $valuation = (int) ($item['productValuation'] ?? 0);
            // WB Feedbacks API не фильтрует по оценке — применяем на нашей стороне после ответа API.
            if ($ratings !== [] && ! in_array($valuation, $ratings, true)) {
                $skippedByRating++;
                continue;
            }

            $productNmId = $item['productDetails']['nmId'] ?? null;
            $photo = null;
            try {
                $images = $this->getProductImages(1, $productNmId);
                $photo = $images[0]['imageS'] ?? null;
            } catch (\Throwable) {
                $photo = null;
            }

            $feedbacks[] = [
                'id' => $item['id'],
                'name' => $item['userName'],
                'answer' => $item['answer'],
                'text' => $item['text'],
                'pros' => $item['pros'],
                'cons' => $item['cons'],
                'createdDate' => Carbon::parse($item['createdDate'])->format('d.m.Y H:i:s'),
                'photoLinks' => $item['photoLinks'],
                'productValuation' => $valuation,
                'productDetails' => [
                    'brandName' => $brandName,
                    'nmId' => $productNmId,
                    'productName' => $item['productDetails']['productName'] ?? null,
                    'supplierArticle' => $item['productDetails']['supplierArticle'] ?? null,
                    'photo' => $photo,
                ],
            ];
        }

        $wbCount = $fetched['wb_count_unanswered'];
        $fetchedCount = count($rawFeedbacks);
        $truncated = (bool) ($fetched['truncated'] ?? false);

        return response()->json([
            "success" => true,
            "messages" => ["Список отзывов получен"],
            "data" => [
                'feedbacks' => $feedbacks,
                'countUnanswered' => count($feedbacks),
                'countFromWb' => $fetchedCount,
                'wbCountUnanswered' => $wbCount,
                'pagesFetched' => $fetched['pages'],
                'truncated' => $truncated,
                'filters' => [
                    'nmId' => $nmId,
                    'ratings' => $ratings,
                    'brand_filter_active' => $brandFilterActive,
                    'brands' => $brandList,
                    'skipped_by_brand' => $skippedByBrand,
                    'skipped_by_rating' => $skippedByRating,
                ],
            ],
        ], 200);
    }

    /**
     * Public wrapper for unit tests / callers.
     */
    public function extractCountUnanswered(mixed $payload): ?int
    {
        return $this->extractCountUnansweredPayload($payload);
    }

    /**
     * @return list<string>
     */
    private function parseCabinetBrands(?string $brands): array
    {
        if ($brands === null || trim($brands) === '') {
            return [];
        }

        return collect(explode(',', $brands))
            ->map(fn ($b) => trim((string) $b))
            ->filter(fn ($b) => $b !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $allowedBrands
     */
    private function brandAllowed(string $feedbackBrand, array $allowedBrands): bool
    {
        $needle = mb_strtolower(trim($feedbackBrand));
        if ($needle === '') {
            return false;
        }

        foreach ($allowedBrands as $allowed) {
            if (mb_strtolower(trim($allowed)) === $needle) {
                return true;
            }
        }

        return false;
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
        return $this->extractWbFeedbacksErrorMessage($payload);
    }
}
