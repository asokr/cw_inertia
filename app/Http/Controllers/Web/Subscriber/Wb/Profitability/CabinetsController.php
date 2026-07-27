<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Profitability;

use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Services\Subscriber\Wb\WbCabinetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CabinetsController extends SubscriberToolController
{
    public function __construct(
        private readonly WbCabinetService $wbCabinets,
    ) {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $cabinet = $this->wbCabinets->selectedFor($request->user());

        if (! $cabinet) {
            return Inertia::render('Subscriber/Wb/Shared/NoCabinet', [
                'toolName' => 'Рентабельность Wildberries',
                'breadcrumbs' => [
                    ['label' => 'Главная', 'href' => '/panel'],
                    ['label' => 'Рентабельность Wildberries'],
                ],
            ]);
        }

        return redirect()->route('subscriber.wb.profitability.index');
    }

    public function store(): RedirectResponse
    {
        return redirect()
            ->route('subscriber.wb.cabinets.index')
            ->with('error', 'Создавайте кабинеты на странице «Общие кабинеты».');
    }

    public function update(): RedirectResponse
    {
        return redirect()
            ->route('subscriber.wb.cabinets.index')
            ->with('error', 'Управляйте кабинетами на странице «Общие кабинеты».');
    }

    public function destroy(): RedirectResponse
    {
        return redirect()
            ->route('subscriber.wb.cabinets.index')
            ->with('error', 'Управляйте кабинетами на странице «Общие кабинеты».');
    }
}
