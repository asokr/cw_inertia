<?php

namespace App\Http\Controllers\Web\Admin\Wb;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexWbApiUsageRequest;
use App\Services\Admin\AdminWbApiUsageService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function widgetIndex(Request $request): JsonResponse
    {
        $statDate = $this->resolveStatDate($request->input('date'));
        $perPage = (int) $request->input('per_page', 50);
        $perPage = $perPage > 0 ? $perPage : 50;

        $payload = $this->wbApiUsageService->widgetData(
            $statDate,
            $request->filled('legal_entity') ? (string) $request->input('legal_entity') : null,
            $request->filled('seller_id') ? (string) $request->input('seller_id') : null,
            $perPage,
        );

        $meta = $payload['meta'];
        unset($payload['meta']);
        $payload['items'] = $payload['items']->values()->all();

        return response()->json([
            'success' => true,
            'messages' => ['Статистика запросов к API Wildberries'],
            'data' => $payload,
            'meta' => $meta,
        ], 200);
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