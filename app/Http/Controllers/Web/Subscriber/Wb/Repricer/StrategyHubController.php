<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Repricer;

use App\Http\Controllers\Web\Subscriber\Concerns\ResolvesSelectedWbCabinet;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Models\Subscribers\Wb\WbCabinet;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StrategyHubController extends SubscriberToolController
{
    use ResolvesSelectedWbCabinet;

    public function show(Request $request): Response
    {
        $cabinetOrResponse = $this->requireSelectedWbCabinet($request, 'Репрайсер цен Wildberries', [
            ['label' => 'Главная', 'href' => '/panel'],
            ['label' => 'Репрайсер цен Wildberries'],
        ]);
        if ($cabinetOrResponse instanceof Response) {
            return $cabinetOrResponse;
        }
        /** @var WbCabinet $cabinet */
        $cabinet = $cabinetOrResponse;

        return Inertia::render('Subscriber/Wb/Repricer/Cabinet/Show', [
            'cabinet' => [
                'id' => $cabinet->id,
                'name' => $cabinet->name,
            ],
            'strategies' => [
                [
                    'key' => 'time',
                    'title' => 'По времени',
                    'description' => 'Управление ценой в зависимости от времени суток.',
                    'href' => route('subscriber.wb.repricer.time.index'),
                ],
                [
                    'key' => 'stocks',
                    'title' => 'От остатков',
                    'description' => 'Повышение стоимости товара в зависимости от остатков на складах WB.',
                    'href' => route('subscriber.wb.repricer.stocks.index'),
                ],
            ],
        ]);
    }
}
