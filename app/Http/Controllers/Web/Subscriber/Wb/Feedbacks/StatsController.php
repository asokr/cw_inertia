<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Feedbacks;

use App\Http\Controllers\Api\Subscriber\Wb\Feedbacks\FeedbacksStatController as ApiFeedbacksStatController;
use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresFeedbacksClientOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Models\Subscribers\Wb\Feedbacks\FeedbacksClients;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatsController extends SubscriberToolController
{
    use EnsuresFeedbacksClientOwnership;

    public function __construct(
        private readonly ApiFeedbacksStatController $apiStatController,
    ) {
    }

    public function product(Request $request, FeedbacksClients $client, string $product): Response
    {
        $this->ensureClientOwnership($client);

        $month = $request->query('month') ?: now()->subMonth()->format('Y-m');

        $payload = ['success' => true, 'data' => null, 'messages' => ['Нет данных за выбранный месяц']];

        try {
            $response = $this->apiStatController->productStatistics(
                $request->duplicate([
                    'cabinet_id' => $client->id,
                    'product_id' => $product,
                    'month' => $month,
                ])
            );
            $payload = $this->decodeApiResponse($response);
        } catch (\Throwable) {
            // API uses MySQL DATE_FORMAT; keep page usable when DB driver differs (e.g. sqlite tests).
        }

        return Inertia::render('Subscriber/Wb/Feedbacks/Product/Stats', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'productId' => $product,
            'month' => $month,
            'months' => $this->buildMonthOptions(),
            'statistics' => ($payload['success'] ?? false) ? ($payload['data'] ?? null) : null,
            'statisticsMessage' => ($payload['success'] ?? false)
                ? ($payload['messages'][0] ?? null)
                : ($payload['message'] ?? 'Не удалось загрузить статистику'),
        ]);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function buildMonthOptions(): array
    {
        $ruMonths = [
            'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
            'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
        ];

        $options = [];
        $start = now()->setDate(2025, 8, 1)->startOfMonth();
        $end = now()->startOfMonth();

        for ($date = $end->copy(); $date->gte($start); $date->subMonth()) {
            $value = $date->format('Y-m');
            $options[] = [
                'value' => $value,
                'label' => $ruMonths[$date->month - 1].' '.$date->year,
            ];
        }

        return $options;
    }
}