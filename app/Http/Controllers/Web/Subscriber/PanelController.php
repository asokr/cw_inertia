<?php

namespace App\Http\Controllers\Web\Subscriber;

use App\Http\Controllers\Controller;
use App\Services\Subscriber\PanelDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PanelController extends Controller
{
    public function index(Request $request, PanelDashboardService $dashboardService): Response
    {
        return Inertia::render('Subscriber/Panel/Index', [
            'dashboard' => $dashboardService->overview($request->user()),
        ]);
    }
}