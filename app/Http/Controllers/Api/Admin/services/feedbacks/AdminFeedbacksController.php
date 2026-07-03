<?php

namespace App\Http\Controllers\Api\Admin\services\feedbacks;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\Controller;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;

use App\Models\Subscribers\Wb\Feedbacks\Review;
use App\Models\Subscribers\Wb\Feedbacks\ReviewStatistic;
use App\Models\Subscribers\Wb\Feedbacks\ReviewCategoryStatistic;
use App\Http\Traits\WBApiTrait;

class AdminFeedbacksController extends Controller
{
    use WBApiTrait;

    public function cabinetsList()
    {
        $data = FeedbacksClients::with(['subscriber.user'])
            ->select([
                'id',
                'subscriber_id',
                'name',
                'brands',
                'bot_status',
                'ai_status',
                'ai_ratings'
            ])
            ->orderBy('subscriber_id')
            ->get();


        return response()->json(["success" => true, "messages" => ["Данные получены"], "data" => $data], 200);
    }

    public function aiAnswerLogs(Request $request)
    {
        $perPage = $request->input('rows', 25);

        $reviews = Review::with(['botResponse', 'cabinet'])
            ->whereHas('botResponse')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            "success" => true,
            "messages" => ["Данные получены"],
            "data" => $reviews
        ], 200);
    }

    public function cabinetStats(Request $request, $id)
    {
        $validated = $request->validate([
            'stat_type'  => 'sometimes|string|in:weekly,monthly,half_year,yearly',
            'limit'      => 'sometimes|integer|min:1|max:52',
            'date'       => 'sometimes|date',
        ]);

        $statType  = $validated['stat_type'] ?? 'weekly';
        $limit     = $validated['limit'] ?? null;
        $date      = $validated['date'] ?? null;

        $cabinet = FeedbacksClients::find($id);

        if (!$cabinet) {
            return response()->json([
                'success' => false,
                'message' => 'Кабинет не найден',
            ], 404);
        }

        // Для полугода и года агрегируем месячные данные
        if (in_array($statType, ['half_year', 'yearly'])) {
            return $this->getAggregatedStats($id, $cabinet, $statType);
        }

        // Получаем список доступных дат
        $availableDates = ReviewStatistic::where('cabinet_id', $id)
            ->where('stat_type', $statType)
            ->orderByDesc('date')
            ->pluck('date')
            ->map(fn($d) => $d->toDateString())
            ->values();

        $statisticsQuery = ReviewStatistic::where('cabinet_id', $id)
            ->where('stat_type', $statType);

        if ($date) {
            $statisticsQuery->whereDate('date', $date);
        } else {
            $statisticsQuery->orderByDesc('date');
        }

        if ($limit) {
            // Если есть лимит, то берем последние N записей (для графиков)
            // Здесь фильтр по одной дате может конфликтовать, но обычно limit используется отдельно
            $stats = $statisticsQuery->limit($limit)->get();
            if ($stats->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }
            // For series data, we might need simple formatting
            $data = $stats->map(function ($row) {
                return array_merge(['date' => $row->date->toDateString()], $row->stat_data ?? []);
            })->values();

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
                'available_dates' => $availableDates,
                'cabinet' => [
                    'id' => $cabinet->id,
                    'name' => $cabinet->name,
                    'status' => $cabinet->status,
                ]
            ]);
        }

        $statData = $stat->stat_data;

        $topProducts = $statData['top_products'] ?? [];
        foreach ($topProducts as $i => $product) {
            $images = $this->getProductImages(1, $product['product_id']);
            $topProducts[$i]['image'] = $images[0]['imageS'] ?? null;
        }
        $statData['top_products'] = $topProducts;

        $statData['categories'] = ReviewCategoryStatistic::where('cabinet_id', $id)
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
            'stat_date' => $stat->date->toDateString(),
            'available_dates' => $availableDates,
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
                'status' => $cabinet->status,
            ]
        ]);
    }

    public function cabinetAnsweredReviews(Request $request, $id)
    {
        $perPage = max(1, min(50, (int) $request->get('per_page', 6)));
        $onlyWithText  = $request->boolean('has_text');
        $onlyWithPhoto = $request->boolean('has_photo');

        $cabinet = FeedbacksClients::find($id);

        if (!$cabinet) {
            return response()->json([
                'success' => false,
                'message' => 'Кабинет не найден',
            ], 404);
        }

        $query = Review::query();
        $query->where('cabinet_id', $id);

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

        $query->orderByDesc('updated_at');

        $paginated = $query->paginate($perPage);

        $data = $paginated->getCollection()->map(function ($row) {
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
                'has_photo'     => !empty($row->photo_links) && count($row->photo_links) > 0,
                'response'      => $resp ? [
                    'text'         => (string) $resp->response_text,
                    'is_ai'        => (bool) $resp->is_ai_response,
                    'created_at'   => $resp->created_at->toIso8601String(),
                ] : null,
            ];
        });

        return response()->json([
            'success'  => true,
            'messages' => ['Данные получены'],
            'data'     => $data,
            'meta'     => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ]
        ], 200);
    }

    /**
     * Принудительный пересчёт статистики для кабинета
     */
    public function recalculateStats($id)
    {
        $cabinet = FeedbacksClients::find($id);

        if (!$cabinet) {
            return response()->json([
                'success' => false,
                'message' => 'Кабинет не найден',
            ], 404);
        }

        // Запускаем команды пересчёта статистики
        Artisan::call('update:wb-feedbacks-statistics', ['--weekly' => true]);
        Artisan::call('update:wb-feedbacks-statistics', ['--monthly' => true]);
        Artisan::call('update:wb-feedbacks-review-category-statistics', ['--type' => 'weekly']);
        Artisan::call('update:wb-feedbacks-review-category-statistics', ['--type' => 'monthly']);

        return response()->json([
            'success' => true,
            'message' => 'Статистика пересчитана',
        ]);
    }

    /**
     * Агрегация статистики за полгода или год на основе месячных данных
     */
    private function getAggregatedStats($cabinetId, $cabinet, $statType)
    {
        $months = $statType === 'half_year' ? 6 : 12;
        $startDate = now()->subMonths($months)->startOfMonth();

        $stats = ReviewStatistic::where('cabinet_id', $cabinetId)
            ->where('stat_type', 'monthly')
            ->where('date', '>=', $startDate)
            ->orderByDesc('date')
            ->get();

        if ($stats->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => null,
                'stat_date' => null,
                'cabinet' => [
                    'id' => $cabinet->id,
                    'name' => $cabinet->name,
                    'status' => $cabinet->status,
                ],
            ]);
        }

        // Агрегируем данные
        $totalReviews = 0;
        $totalRating = 0;
        $ratingCount = 0;
        $withPhotos = 0;
        $withoutPhotos = 0;
        $topProductsMap = [];
        $topCategoriesMap = [];

        foreach ($stats as $stat) {
            $data = $stat->stat_data ?? [];
            $totalReviews += $data['total_reviews'] ?? 0;

            if (!empty($data['average_rating'])) {
                $totalRating += $data['average_rating'] * ($data['total_reviews'] ?? 1);
                $ratingCount += $data['total_reviews'] ?? 1;
            }

            $withPhotos += $data['photo_stats']['with_photos'] ?? 0;
            $withoutPhotos += $data['photo_stats']['without_photos'] ?? 0;

            // Агрегируем топ товары
            foreach ($data['top_products'] ?? [] as $product) {
                $pid = $product['product_id'];
                if (!isset($topProductsMap[$pid])) {
                    $topProductsMap[$pid] = ['product_id' => $pid, 'review_count' => 0, 'image' => $product['image'] ?? null];
                }
                $topProductsMap[$pid]['review_count'] += $product['review_count'] ?? 0;
            }
        }

        // Сортируем и берём топ-5 товаров
        usort($topProductsMap, fn($a, $b) => $b['review_count'] - $a['review_count']);
        $topProducts = array_slice(array_values($topProductsMap), 0, 5);

        // Добавляем изображения для товаров
        foreach ($topProducts as $i => $product) {
            $images = $this->getProductImages(1, $product['product_id']);
            $topProducts[$i]['image'] = $images[0]['imageS'] ?? null;
        }

        // Получаем агрегированные категории
        $categoriesQuery = ReviewCategoryStatistic::where('cabinet_id', $cabinetId)
            ->where('stat_type', 'monthly')
            ->where('date', '>=', $startDate);

        $categories = $categoriesQuery->get()
            ->groupBy('subject_name')
            ->map(function ($items, $subjectName) {
                $totalReviews = 0;
                $totalRating = 0;
                $ratingCount = 0;
                $withPhotos = 0;
                $withoutPhotos = 0;

                foreach ($items as $item) {
                    $data = $item->stat_data ?? [];
                    $totalReviews += $data['total_reviews'] ?? 0;
                    if (!empty($data['average_rating'])) {
                        $totalRating += $data['average_rating'] * ($data['total_reviews'] ?? 1);
                        $ratingCount += $data['total_reviews'] ?? 1;
                    }
                    $withPhotos += $data['photo_stats']['with_photos'] ?? 0;
                    $withoutPhotos += $data['photo_stats']['without_photos'] ?? 0;
                }

                return [
                    'subject_name' => $subjectName,
                    'total_reviews' => $totalReviews,
                    'average_rating' => $ratingCount > 0 ? round($totalRating / $ratingCount, 2) : null,
                    'photo_stats' => [
                        'with_photos' => $withPhotos,
                        'without_photos' => $withoutPhotos,
                    ],
                ];
            })
            ->sortByDesc('total_reviews')
            ->values();

        $aggregatedData = [
            'stat_data' => [
                'total_reviews' => $totalReviews,
                'average_rating' => $ratingCount > 0 ? round($totalRating / $ratingCount, 2) : null,
                'photo_stats' => [
                    'with_photos' => $withPhotos,
                    'without_photos' => $withoutPhotos,
                ],
                'top_products' => $topProducts,
                'categories' => $categories,
            ],
        ];

        $periodLabel = $statType === 'half_year' ? '6 месяцев' : '12 месяцев';
        $dateFrom = $startDate->format('d.m.Y');
        $dateTo = now()->format('d.m.Y');

        return response()->json([
            'success' => true,
            'data' => $aggregatedData,
            'stat_date' => "{$dateFrom} - {$dateTo}",
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
                'status' => $cabinet->status,
            ],
        ]);
    }
}
