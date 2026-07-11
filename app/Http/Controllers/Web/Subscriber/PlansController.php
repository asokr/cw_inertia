<?php

namespace App\Http\Controllers\Web\Subscriber;

use App\Http\Controllers\Controller;
use App\Services\Subscriber\PlansPageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlansController extends Controller
{
    public function index(Request $request, PlansPageService $service): Response
    {
        if ($request->query('payment') === 'success') {
            session()->flash('success', 'Оплата прошла успешно. Тариф активирован.');
        }

        $data = $service->forUser($request->user());

        return Inertia::render('Subscriber/Plans/Index', [
            'plans' => $data['plans'],
            'subscriptionData' => $data['subscription_data'],
            'nextActions' => $data['next_actions'],
            'pendingDowngrade' => $data['pending_downgrade'],
        ]);
    }
}