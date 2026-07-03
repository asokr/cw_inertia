<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Repricer;

use App\Http\Controllers\Web\Subscriber\Concerns\EnsuresRepricerCabinetOwnership;
use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Models\Subscribers\Wb\Repricer\RepricerCabinets;
use Inertia\Inertia;
use Inertia\Response;

class StrategyHubController extends SubscriberToolController
{
    use EnsuresRepricerCabinetOwnership;

    public function show(RepricerCabinets $cabinet): Response
    {
        $this->ensureCabinetOwnership($cabinet);

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
                    'href' => route('subscriber.wb.repricer.cabinets.time.index', $cabinet->id),
                ],
                [
                    'key' => 'stocks',
                    'title' => 'От остатков',
                    'description' => 'Повышение стоимости товара в зависимости от остатков на складах WB.',
                    'href' => route('subscriber.wb.repricer.cabinets.stocks.index', $cabinet->id),
                ],
            ],
        ]);
    }
}