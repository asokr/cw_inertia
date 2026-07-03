<?php

namespace App\Http\Controllers\Web\Admin\Repricer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexRepricerRequest;
use App\Services\Admin\AdminRepricerService;
use Inertia\Inertia;
use Inertia\Response;

class NmidController extends Controller
{
    public function __construct(private readonly AdminRepricerService $repricerService)
    {
    }

    public function index(IndexRepricerRequest $request): Response
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 25);
        $cabinetId = isset($filters['cabinet_id']) ? (int) $filters['cabinet_id'] : null;

        $nmids = $this->repricerService->paginateNmIds($cabinetId, $perPage);

        return Inertia::render('Admin/Services/Repricer/Nmids/Index', [
            'nmids' => $nmids,
            'filters' => [
                'per_page' => $perPage,
                'cabinet_id' => $cabinetId,
            ],
        ]);
    }
}