<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Команды Artisan, предоставленные вашим приложением.
     *
     * @var array
     */
    protected $commands = [
        Commands\DeleteFiles::class,
        Commands\WbPriceCalculationCommissions::class,
        Commands\SubscriberCheckSubscription::class,
        Commands\SubscriberWbFeedbacksAnswer::class,
        Commands\UpdateWbFeedbacksStatistics::class,
        Commands\UpdateWbFeedbacksReviewProductStatistics::class,
        Commands\UpdateWbFeedbacksReviewCategoryStatistics::class,
        Commands\SubscriberNotifyNotEnoughFunds::class,
        Commands\WbRepricerBot::class,
        // Commands\WbStocksRepricerBot::class,
        Commands\DispatchRepricerStocksJobCommand::class,
        Commands\DispatchRepricerStrategyOneJobCommand::class,
        Commands\OzFeedbacksAnswer::class,
        Commands\DispatchRepricerCompetitorsJobCommand::class,
        Commands\ResetStuckProfitabilityReportsCommand::class,
        Commands\CleanupAiMediaPreviousMonthCommand::class,
        Commands\AggregateAiCosts::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {

        // Получим коммиссии по категориям для WB
        $schedule->command('subscriber:wb-price-calculation-commissions')->daily()->at('03:05');

        // Отчистим директории с не нужными файлами
        $schedule->command('appfiles:delete')->daily()->at('02:05');
        // Отчистим логи ChatGPT
        $schedule->command('subscriber:wb-feedbacks-log-clear')->daily()->at('01:05');
        // Очистка логов API запросов WB старше 7 дней
        $schedule->command('wb:cleanup-request-logs')->daily()->at('04:00');
        // Очистка логов AI маркетплейса старше 1 месяца
        $schedule->command('model:prune --model="App\\Models\\AiRequestLog"')->daily()->at('04:10');
        // Агрегация расходов AI за текущий день в постоянную таблицу
        $schedule->command('ai:aggregate-costs --date=' . now()->toDateString())
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();
        // Очистка AI-медиа файлов за предыдущий месяц
        $schedule->command('ai-media:cleanup-previous-month')->monthlyOn(20, '04:20')->withoutOverlapping()->onOneServer()->runInBackground();

        /*
        /   Задачи для сервиса подписок
        /
        */

        // Проверим подписки
        $schedule->command('subscriber:check')->everyTenMinutes();

        // Отправим уведомления связанные с подпиской
        $schedule->command('subscriber:notify')->dailyAt('13:00');

        // Сервис ответов на отзывы WB
        $schedule->command('subscriber:wb-feedbacks-answer')->hourly();

        // Агрегация по категориям (ежедневное обновление текущего месяца и недели)
        $schedule->command('update:wb-feedbacks-review-category-statistics --type=monthly')->dailyAt('02:04');
        $schedule->command('update:wb-feedbacks-review-category-statistics --type=weekly')->dailyAt('02:06');

        // Еженедельная статистика (запуск каждый понедельник в 2:00)
        $schedule->command('update:wb-feedbacks-statistics --weekly')
            ->dailyAt('02:00') // Понедельник (1), время 2:00
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        // Ежемесячная статистика (запуск каждое 1-е число в 2:00)
        $schedule->command('update:wb-feedbacks-statistics --monthly')
            ->dailyAt('02:02') // Первое число, время 2:00
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        // Агрегируем статистику по товарам для каждого кабинета
        $schedule->command('update:wb-feedbacks-review-product-statistics')
            ->dailyAt('23:30')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();

        // Сервис ответов на отзывы Ozon
        $schedule->command('subscriber:oz-feedbacks-answer')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();


        // Репрайсер по времени
        $schedule->command('subscriber:wb-repricer-bot')->withoutOverlapping()->everyMinute();

        // Репрайсер по остаткам
        // $schedule->command('subscriber:wb-stocks-repricer-bot')->withoutOverlapping()->everyThirtyMinutes();
        // $schedule->command('subscriber:wb-stocks-repricer-bot')->everyTenMinutes()->withoutOverlapping()->runInBackground();

        // Постановка задач обновления остатков
        $schedule->command('subscriber:dispatch-wb-stocks-jobs')->everyThirtyMinutes()->withoutOverlapping()->runInBackground();

        // Постановка задач изменения цен по остаткам
        $schedule->command('subscriber:dispatch-wb-price-jobs')->everyThirtyMinutes()->withoutOverlapping()->runInBackground();

        // Постановка задач конкурентного репрайсера
        $schedule->command('subscriber:dispatch-wb-competitor-jobs')->everyTenMinutes()->withoutOverlapping()->runInBackground();

        // Сброс зависших profitability-отчётов (порог 35 мин = timeout Job 30 мин + запас на очередь)
        $schedule->command('subscriber:fail-stuck-profitability-reports --minutes=35')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
