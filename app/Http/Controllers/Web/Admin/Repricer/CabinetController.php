<?php

namespace App\Http\Controllers\Web\Admin\Repricer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRepricerRequest;
use App\Services\Admin\AdminRepricerService;
use Inertia\Inertia;
use Inertia\Response;

class CabinetController extends Controller
{
    public function __construct(private readonly AdminRepricerService $repricerService)
    {
    }

    public function index(IndexRepricerRequest $request): Response
    {
        $perPage = (int) ($request->validated('per_page') ?? 25);
        $cabinets = $this->repricerService->paginateCabinets($perPage);

        return Inertia::render('Admin/Services/Repricer/Cabinets/Index', [
            'cabinets' => $cabinets,
            'filters' => ['per_page' => $perPage],
        ]);
    }
}