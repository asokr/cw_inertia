<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscribers\SubscribersPlans;
use App\Models\Subscribers\Wb\Feedbacks\Review;
use App\Models\Subscribers\SubscribersSubscriptions;
use App\Models\Subscribers\Wb\Feedbacks\ReviewStatistic;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;

class UpdateWbFeedbacksStatistics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:wb-feedbacks-statistics {--weekly} {--monthly}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Обновление статистики отзывов для каждого кабинета активных подписчиков Wildberries';


    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Начало обновления статистики по кабинетам...');

        // Получаем активных подписчиков с нужным тарифом
        $subscriptions = $this->getActiveSubscriptions();

        // Определяем, какие типы статистики обновлять
        $updateWeekly = $this->option('weekly');
        $updateMonthly = $this->option('monthly');

        if (!$updateWeekly && !$updateMonthly) {
            $this->error('Не указан ни один тип статистики. Используйте --weekly или --monthly.');
            return;
        }

        // Проходим по каждому подписчику
        foreach ($subscriptions as $subscription) {
            // Получаем кабинеты подписчика
            $clients = FeedbacksClients::where('subscriber_id', $subscription->subscribers_id)
                ->where(function ($query) {
                    $query->where('ai_status', 1)
                        ->orWhere('bot_status', 1);
                })
                ->get();

            // Обрабатываем каждый кабинет
            foreach ($clients as $client) {
                $this->info("Обновляем статистику для кабинета ID: {$client->id} (Подписчик ID: {$subscription->subscribers_id})");

                // Считаем статистику за неделю (если указана опция)
                if ($updateWeekly) {
                    $this->updateStatistics($client->id, $this->getWeeklyDateRange(), 'weekly');
                }

                // Считаем статистику за месяц (если указана опция)
                if ($updateMonthly) {
                    $this->updateStatistics($client->id, $this->getMonthlyDateRange(), 'monthly');
                }
            }
        }

        $this->info('Обновление статистики завершено.');
    }

    /**
     * Получение активных подписчиков с нужным тарифом.
     */
    private function getActiveSubscriptions()
    {
        $subscriptions = SubscribersSubscriptions::where('status', 1)->get();
        $subscriberSubscriptions = [];

        foreach ($subscriptions as $subscription) {
            $modelPlan = SubscribersPlans::find($subscription->plan_id);

            if (in_array('subscriber wb feedbacks', $modelPlan->permissions)) {
                $subscriberSubscriptions[] = $subscription;
            }
        }

        return $subscriberSubscriptions;
    }

    /**
     * Обновление статистики для конкретного кабинета.
     */
    private function updateStatistics($cabinetId, $dateRange, $period)
    {
        [$startDate, $endDate] = $dateRange;

        // Считаем общее количество отзывов
        $totalReviews = Review::where('cabinet_id', $cabinetId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        // Считаем средний рейтинг
        $averageRating = Review::where('cabinet_id', $cabinetId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->avg('rating');

        // Считаем топ товаров
        $topProducts = Review::select('product_id', \DB::raw('COUNT(*) as review_count'))
            ->where('cabinet_id', $cabinetId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('product_id')
            ->orderByDesc('review_count')
            ->take(5)
            ->get()
            ->map(function ($product) {
                return [
                    'product_id' => $product->product_id,
                    'review_count' => $product->review_count,
                ];
            });

        // Считаем часто упоминаемые впечатления
        $bablesCounts = Review::select(\DB::raw('JSON_UNQUOTE(JSON_EXTRACT(bables, CONCAT("$[", numbers.n, "]"))) as bable'), \DB::raw('COUNT(*) as count'))
            ->crossJoin(\DB::raw('(SELECT 0 as n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) numbers'))
            ->where('cabinet_id', $cabinetId)
            ->whereRaw('JSON_LENGTH(bables) > numbers.n')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('bable')
            ->orderByDesc('count')
            ->take(5)
            ->get()
            ->map(function ($item) {
                return [
                    'bable' => $item->bable,
                    'count' => $item->count,
                ];
            });

        // Распределение отзывов по рейтингам (1-5 звёзд)
        $ratingDistribution = Review::select('rating', \DB::raw('COUNT(*) as count'))
            ->where('cabinet_id', $cabinetId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy('rating')
            ->orderBy('rating')
            ->get()
            ->pluck('count', 'rating')
            ->toArray();



        // Статистика по отзывам с фото
        $photoStats = Review::where('cabinet_id', $cabinetId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
        COUNT(CASE WHEN JSON_LENGTH(photo_links) > 0 THEN 1 END) as with_photos,
        COUNT(CASE WHEN JSON_LENGTH(photo_links) = 0 OR photo_links IS NULL THEN 1 END) as without_photos
    ')
            ->first();


        // Формируем данные для сохранения
        $statistics = [
            'total_reviews' => $totalReviews,
            'average_rating' => round($averageRating, 2),
            'top_products' => $topProducts,
            'rating_distribution' => $ratingDistribution,
            'photo_stats' => [
                'with_photos' => $photoStats->with_photos ?? 0,
                'without_photos' => $photoStats->without_photos ?? 0,
            ],
            'bables' => $bablesCounts,
        ];


        if ($totalReviews > 0) {
            // Сохраняем статистику в таблицу
            ReviewStatistic::updateOrCreate(
                [
                    'cabinet_id' => $cabinetId,
                    'date' => $startDate->toDateString(),
                    'stat_type' => $period, // weekly или monthly
                ],
                [
                    'stat_data' => $statistics,
                ]
            );

            $this->info("Статистика ({$period}) для кабинета ID: {$cabinetId} обновлена.");
        }
    }

    /**
     * Получение диапазона дат для недельной статистики.
     */
    private function getWeeklyDateRange()
    {
        $endDate = now()->startOfWeek()->subSecond(); // Воскресенье 23:59:59
        $startDate = $endDate->copy()->subDays(6)->startOfDay(); // Понедельник 00:00:00
        return [$startDate, $endDate];
    }

    /**
     * Получение диапазона дат для месячной статистики.
     */
    private function getMonthlyDateRange()
    {
        $endDate = now()->startOfMonth()->subSecond(); // Последний день прошлого месяца 23:59:59
        $startDate = $endDate->copy()->startOfMonth(); // Первый день прошлого месяца 00:00:00
        return [$startDate, $endDate];
    }
}
