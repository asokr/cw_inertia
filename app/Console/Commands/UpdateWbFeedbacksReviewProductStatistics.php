<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscribers\Wb\Feedbacks\Review;
use App\Models\Subscribers\Wb\Feedbacks\ReviewProductStatistic;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;

class UpdateWbFeedbacksReviewProductStatistics extends Command
{
    protected $signature = 'update:wb-feedbacks-review-product-statistics';
    protected $description = 'Собирает месячные срезы статистики по отзывам для каждого товара и кабинета';

    public function handle()
    {
        $this->info('Начало сбора месячной статистики по товарам для всех кабинетов...');

        [$startDate, $endDate] = $this->getMonthlyDateRange();

        $clients = FeedbacksClients::where(function ($q) {
            $q->where('ai_status', 1)
                ->orWhere('bot_status', 1);
        })->get();

        foreach ($clients as $client) {
            $cabinetId = $client->id;
            $productIds = Review::where('cabinet_id', $cabinetId)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->distinct()
                ->pluck('product_id');

            foreach ($productIds as $productId) {
                // Получаем все отзывы по товару за период одним запросом
                $reviews = Review::where('cabinet_id', $cabinetId)
                    ->where('product_id', $productId)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->get();

                $totalReviews = $reviews->count();
                $averageRating = round($reviews->avg('rating') ?? 0, 2);

                // Корректное распределение по всем звездам (от 1 до 5)
                $ratingDistribution = [];
                for ($i = 1; $i <= 5; $i++) {
                    $ratingDistribution[strval($i)] = 0;
                }
                foreach ($reviews as $review) {
                    $star = strval($review->rating);
                    if (isset($ratingDistribution[$star])) {
                        $ratingDistribution[$star]++;
                    }
                }

                // Фото: считаем и собираем
                $photoUrls = [];
                $withPhotos = 0;
                $withoutPhotos = 0;
                foreach ($reviews as $review) {
                    // photo_links всегда массив (cast в модели)
                    if (count($review->photo_links)) {
                        $withPhotos++;
                        foreach ($review->photo_links as $photo) {
                            if (isset($photo['fullSize'], $photo['miniSize'])) {
                                $photoUrls[] = [
                                    'fullSize' => $photo['fullSize'],
                                    'miniSize' => $photo['miniSize'],
                                ];
                            }
                        }
                    } else {
                        $withoutPhotos++;
                    }
                }

                // Часто упоминаемые бэйблы (впечатления)
                $bablesCounts = $reviews
                    ->flatMap(function ($review) {
                        return is_array($review->bables) ? $review->bables : [];
                    })
                    ->filter()
                    ->countBy()
                    ->sortDesc()
                    ->take(10)
                    ->map(function ($count, $bable) {
                        return [
                            'bable' => $bable,
                            'count' => $count,
                        ];
                    })
                    ->values();

                $allPros = [];
                $allCons = [];
                foreach ($reviews as $review) {
                    if (!empty($review->pros)) {
                        $allPros[] = $review->pros;
                    }
                    if (!empty($review->cons)) {
                        $allCons[] = $review->cons;
                    }
                }

                $statData = [
                    'total_reviews' => $totalReviews,
                    'average_rating' => $averageRating,
                    'rating_distribution' => $ratingDistribution,
                    'photo_stats' => [
                        'with_photos' => $withPhotos,
                        'without_photos' => $withoutPhotos,
                    ],
                    'photo_urls' => $photoUrls,
                    'bables' => $bablesCounts,
                ];

                $statDate = $startDate->toDateString();

                ReviewProductStatistic::updateOrCreate(
                    [
                        'cabinet_id' => $cabinetId,
                        'product_id' => $productId,
                        'date' => $statDate,
                    ],
                    [
                        'stat_data' => $statData,
                        'pros_cons_data' => [
                            'pros' => $allPros,
                            'cons' => $allCons,
                        ],
                    ],

                );

                $this->info("Статистика для товара {$productId} (кабинет {$cabinetId}) за месяц {$statDate} собрана.");
            }
        }

        $this->info('Месячная статистика по товарам успешно собрана.');
    }

    private function getMonthlyDateRange()
    {
        $startDate = now()->startOfMonth();
        $endDate = now()->endOfDay();
        return [$startDate, $endDate];
    }
}
