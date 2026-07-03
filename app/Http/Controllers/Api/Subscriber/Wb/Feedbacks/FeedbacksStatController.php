<?php

namespace App\Http\Controllers\Api\Subscriber\Wb\Feedbacks;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Traits\WBApiTrait;
use App\Http\Controllers\Controller;
use App\Models\Subscribers\Wb\Feedbacks\Review;
use App\Models\Subscribers\Wb\Feedbacks\ReviewStatistic;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\Feedbacks\ReviewProductStatistic;
use App\Models\Subscribers\Wb\Feedbacks\ReviewCategoryStatistic;

class FeedbacksStatController extends Controller
{

    use WBApiTrait;

    /**
     * Получить срезы статистики отзывов (недельные или месячные) по кабинету.
     *
     * @queryParam cabinet_id integer required ID кабинета.
     * @queryParam stat_type string optional weekly|monthly (по умолчанию weekly)
     * @queryParam limit integer optional Количество последних срезов (от 1 до 26). Если не указано — возвращается самый свежий.
     */
    public function stats(Request $request)
    {

        $validated = $request->validate([
            'cabinet_id' => 'required|integer',
            'stat_type'  => 'sometimes|string|in:weekly,monthly',
            'limit'      => 'sometimes|integer|min:1|max:26',
        ]);

        $cabinetId = $validated['cabinet_id'];
        $statType  = $validated['stat_type'] ?? 'weekly';
        $limit     = $validated['limit'] ?? null;

        // Получаем кабинет и проверяем владельца
        $cabinet = FeedbacksClients::find($cabinetId);

        if (!$cabinet) {
            return response()->json([
                'success' => false,
                'message' => 'Кабинет не найден',
            ], 404);
        }

        $subscriber_id = auth()->user()->subscriber->id;
        // Проверим, принадлежит-ли кабинет текущему юзеру
        if ($cabinet->subscriber_id != $subscriber_id) {
            if ($cabinet->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden'
                ], 403);
            }
        }

        $statisticsQuery = ReviewStatistic::where('cabinet_id', $cabinetId)
            ->where('stat_type', $statType)
            ->orderByDesc('date');

        if ($limit) {
            $stats = $statisticsQuery->limit($limit)->get();
            if ($stats->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }
            $data = $stats->map(fn($row) => $this->formatRow($row))->values();
            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        }

        $stat = $statisticsQuery->first();
        if (!$stat) {
            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        }

        $statData = $stat->stat_data; // Получили как массив

        $topProducts = $statData['top_products'] ?? [];
        foreach ($topProducts as $i => $product) {
            $images = $this->getProductImages(1, $product['product_id']);
            $topProducts[$i]['image'] = $images[0]['imageS'] ?? null;
        }
        $statData['top_products'] = $topProducts;

        $statData['categories'] = ReviewCategoryStatistic::where('cabinet_id', $cabinetId)
            ->where('stat_type', $statType)
            ->whereDate('date', $stat->date)
            ->get()
            ->map(function ($row) {
                $payload = $row->stat_data ?? [];

                return [
                    'subject_name'     => $row->subject_name,
                    'total_reviews'    => $payload['total_reviews'] ?? 0,
                    'average_rating'   => $payload['average_rating'] ?? null,
                    'photo_stats'      => $payload['photo_stats'] ?? [
                        'with_photos'    => 0,
                        'without_photos' => 0,
                    ],
                ];
            })->sortByDesc('total_reviews')->values();

        $stat->stat_data = $statData;


        return response()->json([
            'success' => true,
            'data' => $stat,
        ]);
    }

    /**
     * Виджет: отзывы, на которые есть ответ ИИ.
     * Поддерживает фильтры:
     * - cabinet_id (int) — ID кабинета
     * - limit (int) — количество записей (по умолчанию 5, макс 100)
     * - has_text=1 — только отзывы с непустым content
     * - has_photo=1 — только отзывы, где есть фото (JSON_LENGTH(photo_links) > 0)
     */
    public function answeredReviews(Request $request)
    {
        $request->validate([
            'cabinet_id' => ['nullable', 'integer', 'min:1'],
            'limit'      => ['nullable', 'integer', 'min:1', 'max:100'],
            'has_text'   => ['nullable'],
            'has_photo'  => ['nullable'],
        ]);

        $cabinetId     = (int) $request->get('cabinet_id');
        $limit         = max(1, min(100, (int) $request->get('limit', 5)));
        $onlyWithText  = $request->boolean('has_text');
        $onlyWithPhoto = $request->boolean('has_photo');

        $cabinet = FeedbacksClients::find($cabinetId);

        if (!$cabinet) {
            return response()->json([
                'success' => false,
                'message' => 'Кабинет не найден',
            ], 404);
        }

        $subscriber_id = auth()->user()->subscriber->id;
        // Проверим, принадлежит-ли кабинет текущему юзеру
        if ($cabinet->subscriber_id != $subscriber_id) {
            if ($cabinet->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden'
                ], 403);
            }
        }

        $query = Review::query();

        if ($cabinetId > 0) {
            $query->where('cabinet_id', $cabinetId);
        }
        if ($onlyWithText) {
            $query->whereNotNull('content')->where('content', '<>', '');
        }
        if ($onlyWithPhoto) {
            $query->whereRaw('JSON_LENGTH(photo_links) > 0');
        }

        // Только отзывы, у которых есть ответ (любой)
        $query->whereHas('botResponse');

        // Жадная подгрузка самого ответа
        $query->with('botResponse');

        $query->orderByDesc('updated_at')->limit($limit);

        $rows = $query->get();

        $data = $rows->map(function ($row) {
            $resp = $row->botResponse;

            $images = $this->getProductImages(1, $row->product_id);
            $productImage = $images[0]['imageS'] ?? null;

            return [
                'id'            => (int) $row->id,
                'product_id'    => (string) $row->product_id,
                'product_image' => $productImage,
                'rating'        => (int) $row->rating,
                'content'       => $row->content,
                'pros'          => $row->pros,
                'cons'          => $row->cons,
                'photo_links'   => $row->photo_links,
                'response'      => $resp ? [
                    'text'         => (string) $resp->response_text,
                    'is_ai'        => (bool) $resp->is_ai_response,
                    'created_at'   => Carbon::parse($resp->created_at)->toIso8601String(),
                ] : null,
            ];
        });

        return response()->json([
            'success'  => true,
            'messages' => ['Данные получены'],
            'data'     => $data,
        ], 200);
    }

    public function productStatistics(Request $request)
    {
        $request->validate([
            'cabinet_id'  => ['required', 'integer', 'min:1'],
            'product_id'  => ['required'],
            'month'       => ['nullable', 'date_format:Y-m'], // Ожидаем формат '2025-09'
        ]);

        $cabinetId = $request->get('cabinet_id');
        $productId = $request->get('product_id');
        $month     = $request->get('month');

        // Проверяем наличие кабинета
        $cabinet = FeedbacksClients::find($cabinetId);

        if (!$cabinet) {
            return response()->json([
                'success' => false,
                'message' => 'Кабинет не найден',
            ], 404);
        }

        $subscriber_id = auth()->user()->subscriber->id;
        // Проверяем принадлежность кабинета текущему пользователю
        if ($cabinet->subscriber_id != $subscriber_id) {
            if ($cabinet->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden'
                ], 403);
            }
        }

        // Если месяц не указан — берём предыдущий месяц
        if (!$month) {
            $month = now()->subMonth()->format('Y-m');
        }

        // Ищем статистику по product_id, cabinet_id, месяцу
        $stat = ReviewProductStatistic::where('cabinet_id', $cabinetId)
            ->where('product_id', $productId)
            ->whereRaw("DATE_FORMAT(`date`, '%Y-%m') = ?", [$month])
            ->first();

        if (!$stat) {
            return response()->json([
                'success'  => true,
                'messages' => ['Нет данных за выбранный месяц'],
                'data'     => null,
            ], 200);
        }

        $images = $this->getProductImages(1, $productId);
        $productImage = $images[0]['imageS'] ?? null;

        $data = $stat->stat_data ?? [];
        $prosConsData = $stat->pros_cons_data ?? [];
        $result = array_merge($data, ['pros_cons_data' => $prosConsData, 'product_image' => $productImage]);

        return response()->json([
            'success'  => true,
            'messages' => ['Данные получены'],
            'data'     => $result,
        ], 200);
    }
}
