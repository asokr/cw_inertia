<?php

namespace App\Services\Admin;

use App\Http\Traits\WBApiTrait;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use App\Models\Subscribers\Wb\Feedbacks\Review;
use App\Models\Subscribers\Wb\Feedbacks\ReviewCategoryStatistic;
use App\Models\Subscribers\Wb\Feedbacks\ReviewStatistic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;

class AdminFeedbacksService
{
    use WBApiTrait;

    public function listCabinets(): Collection
    {
        return FeedbacksClients::query()
            ->with(['subscriber.user:id,name,email'])
            ->select([
                'id',
                'subscriber_id',
                'name',
                'brands',
                'bot_status',
                'ai_status',
                'ai_ratings',
            ])
            ->orderBy('subscriber_id')
            ->get();
    }

    public function aiAnswerLogs(int $perPage = 25): LengthAwarePaginator
    {
        return Review::query()
            ->with(['botResponse', 'cabinet:id,name,subscriber_id'])
            ->whereHas('botResponse')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @return array{success: bool, message?: string, status?: int, data?: mixed, stat_date?: ?string, available_dates?: mixed, cabinet?: array<string, mixed>}
     */
    public function cabinetStats(string $cabinetId, array $filters = []): array
    {
        $statType = $filters['stat_type'] ?? 'weekly';
        $limit = $filters['limit'] ?? null;
        $date = $filters['date'] ?? null;

        $cabinet = FeedbacksClients::find($cabinetId);

        if (! $cabinet) {
            return [
                'success' => false,
                'message' => 'Кабинет не найден',
                'status' => 404,
            ];
        }

        if (in_array($statType, ['half_year', 'yearly'], true)) {
            return $this->getAggregatedStats($cabinetId, $cabinet, $statType);
        }

        $availableDates = ReviewStatistic::where('cabinet_id', $cabinetId)
            ->where('stat_type', $statType)
            ->orderByDesc('date')
            ->pluck('date')
            ->map(fn ($d) => $d->toDateString())
            ->values();

        $statisticsQuery = ReviewStatistic::where('cabinet_id', $cabinetId)
            ->where('stat_type', $statType);

        if ($date) {
            $statisticsQuery->whereDate('date', $date);
        } else {
            $statisticsQuery->orderByDesc('date');
        }

        if ($limit) {
            $stats = $statisticsQuery->limit($limit)->get();
            if ($stats->isEmpty()) {
                return [
                    'success' => true,
                    'data' => [],
                ];
            }

            $data = $stats->map(function ($row) {
                return array_merge(['date' => $row->date->toDateString()], $row->stat_data ?? []);
            })->values();

            return [
                'success' => true,
                'data' => $data,
            ];
        }

        $stat = $statisticsQuery->first();
        if (! $stat) {
            return [
                'success' => true,
                'data' => null,
                'available_dates' => $availableDates,
                'cabinet' => $this->formatCabinet($cabinet),
            ];
        }

        $statData = $stat->stat_data;

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
                    'subject_name' => $row->subject_name,
                    'total_reviews' => $payload['total_reviews'] ?? 0,
                    'average_rating' => $payload['average_rating'] ?? null,
                    'photo_stats' => $payload['photo_stats'] ?? [
                        'with_photos' => 0,
                        'without_photos' => 0,
                    ],
                ];
            })->sortByDesc('total_reviews')->values();

        $stat->stat_data = $statData;

        return [
            'success' => true,
            'data' => $stat,
            'stat_date' => $stat->date->toDateString(),
            'available_dates' => $availableDates,
            'cabinet' => $this->formatCabinet($cabinet),
        ];
    }

    /**
     * @return array{success: bool, message?: string, status?: int}
     */
    public function recalculateStats(string $cabinetId): array
    {
        $cabinet = FeedbacksClients::find($cabinetId);

        if (! $cabinet) {
            return [
                'success' => false,
                'message' => 'Кабинет не найден',
                'status' => 404,
            ];
        }

        Artisan::call('update:wb-feedbacks-statistics', ['--weekly' => true]);
        Artisan::call('update:wb-feedbacks-statistics', ['--monthly' => true]);
        Artisan::call('update:wb-feedbacks-review-category-statistics', ['--type' => 'weekly']);
        Artisan::call('update:wb-feedbacks-review-category-statistics', ['--type' => 'monthly']);

        return [
            'success' => true,
            'message' => 'Статистика пересчитана',
        ];
    }

    /**
     * @return array{success: bool, data: mixed, stat_date: ?string, cabinet: array<string, mixed>}
     */
    private function getAggregatedStats(string $cabinetId, FeedbacksClients $cabinet, string $statType): array
    {
        $months = $statType === 'half_year' ? 6 : 12;
        $startDate = now()->subMonths($months)->startOfMonth();

        $stats = ReviewStatistic::where('cabinet_id', $cabinetId)
            ->where('stat_type', 'monthly')
            ->where('date', '>=', $startDate)
            ->orderByDesc('date')
            ->get();

        if ($stats->isEmpty()) {
            return [
                'success' => true,
                'data' => null,
                'stat_date' => null,
                'cabinet' => $this->formatCabinet($cabinet),
            ];
        }

        $totalReviews = 0;
        $totalRating = 0;
        $ratingCount = 0;
        $withPhotos = 0;
        $withoutPhotos = 0;
        $topProductsMap = [];

        foreach ($stats as $stat) {
            $data = $stat->stat_data ?? [];
            $totalReviews += $data['total_reviews'] ?? 0;

            if (! empty($data['average_rating'])) {
                $totalRating += $data['average_rating'] * ($data['total_reviews'] ?? 1);
                $ratingCount += $data['total_reviews'] ?? 1;
            }

            $withPhotos += $data['photo_stats']['with_photos'] ?? 0;
            $withoutPhotos += $data['photo_stats']['without_photos'] ?? 0;

            foreach ($data['top_products'] ?? [] as $product) {
                $pid = $product['product_id'];
                if (! isset($topProductsMap[$pid])) {
                    $topProductsMap[$pid] = ['product_id' => $pid, 'review_count' => 0, 'image' => $product['image'] ?? null];
                }
                $topProductsMap[$pid]['review_count'] += $product['review_count'] ?? 0;
            }
        }

        usort($topProductsMap, fn ($a, $b) => $b['review_count'] - $a['review_count']);
        $topProducts = array_slice(array_values($topProductsMap), 0, 5);

        foreach ($topProducts as $i => $product) {
            $images = $this->getProductImages(1, $product['product_id']);
            $topProducts[$i]['image'] = $images[0]['imageS'] ?? null;
        }

        $categories = ReviewCategoryStatistic::where('cabinet_id', $cabinetId)
            ->where('stat_type', 'monthly')
            ->where('date', '>=', $startDate)
            ->get()
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
                    if (! empty($data['average_rating'])) {
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

        $dateFrom = $startDate->format('d.m.Y');
        $dateTo = now()->format('d.m.Y');

        return [
            'success' => true,
            'data' => $aggregatedData,
            'stat_date' => "{$dateFrom} - {$dateTo}",
            'cabinet' => $this->formatCabinet($cabinet),
        ];
    }

    /**
     * @return array{id: int|string, name: ?string, status: mixed}
     */
    private function formatCabinet(FeedbacksClients $cabinet): array
    {
        return [
            'id' => $cabinet->id,
            'name' => $cabinet->name,
            'status' => $cabinet->status,
        ];
    }
}