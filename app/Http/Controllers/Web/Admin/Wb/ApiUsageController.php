<?php

namespace App\Http\Controllers\Web\Admin\Wb;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexWbApiUsageRequest;
use App\Services\Admin\AdminWbApiUsageService;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ApiUsageController extends Controller
{
    public function __construct(private readonly AdminWbApiUsageService $wbApiUsageService)
    {
    }

    public function index(IndexWbApiUsageRequest $request): Response
    {
        $validated = $request->validated();
        $statDate = $this->resolveStatDate($validated['date'] ?? null);
        $perPage = (int) ($validated['per_page'] ?? 50);
        $legalEntity = $validated['legal_entity'] ?? null;
        $sellerId = $validated['seller_id'] ?? null;

        $stats = $this->wbApiUsageService->paginateStats($statDate, $legalEntity, $sellerId, $perPage);
        $summary = $this->wbApiUsageService->summaryForDate($statDate, $legalEntity, $sellerId);

        $stats->getCollection()->transform(
            fn ($stat) => $this->wbApiUsageService->formatStatRow($stat)
        );

        return Inertia::render('Admin/Wb/ApiUsage/Index', [
            'stats' => $stats,
            'summary' => $summary,
            'filters' => [
                'date' => $statDate,
                'per_page' => $perPage,
                'legal_entity' => $legalEntity ?? '',
                'seller_id' => $sellerId ?? '',
            ],
        ]);
    }

    private function resolveStatDate(?string $dateInput): string
    {
        if (! $dateInput) {
            return now()->toDateString();
        }

        try {
            return Carbon::parse($dateInput)->toDateString();
        } catch (\Throwable) {
            return now()->toDateString();
        }
    }
}