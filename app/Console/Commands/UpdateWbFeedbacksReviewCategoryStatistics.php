<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscribers\Wb\Feedbacks\Review;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\Feedbacks\ReviewCategoryStatistic;
use Carbon\Carbon;

class UpdateWbFeedbacksReviewCategoryStatistics extends Command
{
    protected $signature = 'update:wb-feedbacks-review-category-statistics {--type=monthly : weekly|monthly}';
    protected $description = 'Агрегирует статистику по категориям (subject_name) внутри кабинета за период и сохраняет в wb_feedbacks_review_category_statistics';

    public function handle()
    {
        $type = strtolower((string)($this->option('type') ?? 'monthly'));
        if (!in_array($type, ['weekly', 'monthly'], true)) {
            $this->warn("Некорректный --type={$type}. Использую monthly.");
            $type = 'monthly';
        }

        [$startDate, $endDate, $statDate] = $this->getDateRange($type);

        $this->info("Старт агрегирования категорий: {$type}. Период: {$startDate->toDateTimeString()} — {$endDate->toDateTimeString()}. Якорная дата: {$statDate->toDateString()}");

        $clients = FeedbacksClients::where(function ($q) {
            $q->where('ai_status', 1)->orWhere('bot_status', 1);
        })->get();

        foreach ($clients as $client) {
            $cabinetId = $client->id;

            // Собираем список категорий (subject_name) с отзывами за период
            $categories = Review::where('cabinet_id', $cabinetId)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->whereNotNull('subject_name')
                ->where('subject_name', '!=', '')
                ->distinct()
                ->pluck('subject_name');

            if ($categories->isEmpty()) {
                $this->line("Кабинет {$cabinetId}: категорий за период не найдено.");
                continue;
            }

            foreach ($categories as $subjectName) {
                // Все отзывы по категории за период
                $reviews = Review::where('cabinet_id', $cabinetId)
                    ->where('subject_name', $subjectName)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->get();

                if ($reviews->isEmpty()) {
                    continue;
                }

                $totalReviews   = $reviews->count();
                $averageRating  = round($reviews->avg('rating') ?? 0, 2);

                // Распределение по звездам (1..5)
                $ratingDistribution = [];
                for ($i = 1; $i <= 5; $i++) {
                    $ratingDistribution[(string)$i] = 0;
                }
                foreach ($reviews as $r) {
                    $star = (string)$r->rating;
                    if (isset($ratingDistribution[$star])) {
                        $ratingDistribution[$star]++;
                    }
                }

                // Фото
                $withPhotos = 0;
                $photoUrls  = []; // можно показать выборку фото по категории
                foreach ($reviews as $r) {
                    // photo_links всегда массив
                    if (count($r->photo_links)) {
                        $withPhotos++;
                        foreach ($r->photo_links as $p) {
                            if (isset($p['fullSize'], $p['miniSize'])) {
                                $photoUrls[] = [
                                    'fullSize' => $p['fullSize'],
                                    'miniSize' => $p['miniSize'],
                                ];
                            }
                        }
                    }
                }
                $withoutPhotos = $totalReviews - $withPhotos;

                // Топ-впечатления (bables) — топ-10
                $bablesCounts = $reviews
                    ->flatMap(function ($r) {
                        return is_array($r->bables) ? $r->bables : [];
                    })
                    ->filter()
                    ->countBy()
                    ->sortDesc()
                    ->take(10)
                    ->map(function ($count, $bable) {
                        return ['bable' => $bable, 'count' => $count];
                    })
                    ->values()
                    ->all();

                // Pros/Cons — строки
                $allPros = [];
                $allCons = [];
                foreach ($reviews as $r) {
                    if (!empty($r->pros)) $allPros[] = $r->pros;
                    if (!empty($r->cons)) $allCons[] = $r->cons;
                }

                // Топ-товары внутри категории (по числу отзывов) — полезно для дрила
                $topProducts = $reviews
                    ->groupBy('product_id')
                    ->map->count()
                    ->sortDesc()
                    ->take(5)
                    ->map(function ($count, $productId) {
                        return ['product_id' => (string)$productId, 'reviews' => $count];
                    })
                    ->values()
                    ->all();

                $statData = [
                    'period' => [
                        'from' => $startDate->toDateString(),
                        'to'   => $endDate->toDateString(),
                    ],
                    'total_reviews'       => $totalReviews,
                    'average_rating'      => $averageRating,
                    'rating_distribution' => $ratingDistribution,
                    'photo_stats'         => [
                        'with_photos'    => $withPhotos,
                        'without_photos' => $withoutPhotos,
                    ],
                    'bables'          => $bablesCounts,
                    'pros_cons_data'  => [
                        'pros' => $allPros,
                        'cons' => $allCons,
                    ],
                    // небольшой набор миниатюр для превью в UI (не тащим все, чтобы не раздувать JSON)
                    'photo_preview'   => array_slice($photoUrls, 0, 12),
                    'top_products'    => $topProducts,
                ];

                if ($totalReviews) {
                    ReviewCategoryStatistic::updateOrCreate(
                        [
                            'cabinet_id'   => $cabinetId,
                            'subject_name' => $subjectName,
                            'date'         => $statDate->toDateString(), // якорная дата периода
                            'stat_type'    => $type,
                        ],
                        [
                            'stat_data' => $statData,
                        ]
                    );

                    $this->line("Кабинет {$cabinetId} / Категория '{$subjectName}' — сохранён срез ({$type}) за {$statDate->toDateString()}.");
                }
            }
        }

        $this->info('Агрегирование статистики по категориям завершено.');
        return self::SUCCESS;
    }

    /**
     * Возвращает: [startDate, endDate, statDateAnchor]
     * Диапазон всегда "текущий период → сейчас".
     * anchor = начало периода (1-е число месяца или понедельник).
     */
    private function getDateRange(string $type): array
    {
        $now = now();

        if ($type === 'weekly') {
            // Понедельник 00:00:00 текущей недели → сейчас
            $start  = $now->copy()->startOfWeek()->startOfDay();
            $end    = $now->copy();
            $anchor = $start->copy();
            return [$start, $end, $anchor];
        }

        // По умолчанию monthly: 1-е число текущего месяца 00:00:00 → сейчас
        $start  = $now->copy()->startOfMonth()->startOfDay();
        $end    = $now->copy();
        $anchor = $start->copy();
        return [$start, $end, $anchor];
    }
}
