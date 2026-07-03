<?php

namespace App\Http\Controllers\Web\Admin\Ai;

use App\Enums\AiTaskType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexMarketplaceLogsRequest;
use App\Services\Admin\AdminAiMarketplaceLogService;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceLogController extends Controller
{
    public function __construct(private readonly AdminAiMarketplaceLogService $marketplaceLogService)
    {
    }

    public function index(IndexMarketplaceLogsRequest $request): Response
    {
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 25);
        $page = (int) ($validated['page'] ?? 1);

        $logs = $this->marketplaceLogService->paginate(
            [
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'task_type' => $validated['task_type'] ?? null,
                'status_code' => $validated['status_code'] ?? null,
                'search' => $validated['search'] ?? null,
            ],
            $perPage,
            $page,
        );

        return Inertia::render('Admin/Services/Ai/MarketplaceLogs/Index', [
            'logs' => $logs,
            'taskTypes' => collect(AiTaskType::cases())->map(fn ($case) => [
                'value' => $case->value,
                'label' => $case->value,
            ])->values()->all(),
            'filters' => [
                'date_from' => $validated['date_from'] ?? '',
                'date_to' => $validated['date_to'] ?? '',
                'task_type' => $validated['task_type'] ?? '',
                'status_code' => $validated['status_code'] ?? '',
                'search' => $validated['search'] ?? '',
                'per_page' => $perPage,
            ],
        ]);
    }
}