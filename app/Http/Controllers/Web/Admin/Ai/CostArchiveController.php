<?php

namespace App\Http\Controllers\Web\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAiCostsArchiveRequest;
use App\Services\Admin\AiCostService;
use Inertia\Inertia;
use Inertia\Response;

class CostArchiveController extends Controller
{
    public function __construct(private readonly AiCostService $aiCostService)
    {
    }

    public function index(IndexAiCostsArchiveRequest $request): Response
    {
        $validated = $request->validated();
        $archive = $this->aiCostService->archive(
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
        );

        return Inertia::render('Admin/Services/Ai/CostsArchive/Index', [
            'items' => $archive['items'],
            'totals' => $archive['totals'],
            'filters' => [
                'date_from' => $archive['date_from'],
                'date_to' => $archive['date_to'],
            ],
        ]);
    }
}