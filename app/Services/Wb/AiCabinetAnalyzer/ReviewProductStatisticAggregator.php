<?php

namespace App\Services\Wb\AiCabinetAnalyzer;

use App\Models\Subscribers\Wb\Feedbacks\ReviewProductStatistic;
use Illuminate\Support\Arr;

class ReviewProductStatisticAggregator
{
    /**
     * Возвращает последний месячный срез отзывов по каждому nmid.
     * Данные в таблице уже агрегированы за месяц — период отчёта не учитывается.
     *
     * @param  int[]  $nmids
     * @return array<int, array<string, mixed>>
     */
    public function latestByNmids(array $nmids): array
    {
        $nmids = array_values(array_unique(array_filter(array_map('intval', $nmids), static fn(int $nmid): bool => $nmid > 0)));
        if ($nmids === []) {
            return [];
        }

        $rows = ReviewProductStatistic::query()
            ->whereIn('product_id', $nmids)
            ->orderByDesc('date')
            ->get()
            ->unique(static fn(ReviewProductStatistic $row): int => (int) $row->product_id);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->product_id] = $this->mapRowToReviewsBlock($row);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyReviewsBlock(): array
    {
        return [
            'pros' => [],
            'cons' => [],
            'bables' => [],
            'rating_distribution' => [
                '1' => 0,
                '2' => 0,
                '3' => 0,
                '4' => 0,
                '5' => 0,
            ],
            'average_rating' => 0.0,
            'photo_stats' => [
                'with_photos' => 0,
                'without_photos' => 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRowToReviewsBlock(ReviewProductStatistic $row): array
    {
        $stat = is_array($row->stat_data) ? $row->stat_data : [];
        $prosCons = is_array($row->pros_cons_data) ? $row->pros_cons_data : [];

        $ratingDistribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $starKey = (string) $i;
            $ratingDistribution[$starKey] = (int) (($stat['rating_distribution'] ?? [])[$starKey] ?? 0);
        }

        $pros = [];
        foreach ((array) ($prosCons['pros'] ?? []) as $pro) {
            $proText = trim((string) $pro);
            if ($proText !== '') {
                $pros[] = $proText;
            }
        }

        $cons = [];
        foreach ((array) ($prosCons['cons'] ?? []) as $con) {
            $conText = trim((string) $con);
            if ($conText !== '') {
                $cons[] = $conText;
            }
        }

        return [
            'pros' => $pros,
            'cons' => $cons,
            'bables' => array_values((array) ($stat['bables'] ?? [])),
            'rating_distribution' => $ratingDistribution,
            'average_rating' => round((float) ($stat['average_rating'] ?? 0), 2),
            'photo_stats' => [
                'with_photos' => (int) Arr::get($stat, 'photo_stats.with_photos', 0),
                'without_photos' => (int) Arr::get($stat, 'photo_stats.without_photos', 0),
            ],
        ];
    }
}