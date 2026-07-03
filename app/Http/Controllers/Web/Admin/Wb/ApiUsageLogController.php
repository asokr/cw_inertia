<?php

namespace App\Http\Controllers\Web\Admin\Wb;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexWbApiUsageLogsRequest;
use App\Services\Admin\AdminWbApiUsageService;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ApiUsageLogController extends Controller
{
    public function __construct(private readonly AdminWbApiUsageService $wbApiUsageService)
    {
    }

    public function show(IndexWbApiUsageLogsRequest $request, string $sellerId): Response
    {
        $validated = $request->validated();
        $statDate = $this->resolveStatDate($validated['date'] ?? null);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $page = (int) ($validated['page'] ?? 1);

        $payload = $this->wbApiUsageService->sellerLogs(
            $sellerId,
            $statDate,
            [
                'endpoint' => $validated['endpoint'] ?? null,
                'method' => $validated['method'] ?? null,
                'response_code' => $validated['response_code'] ?? null,
            ],
            $perPage,
            $page,
        );

        return Inertia::render('Admin/Wb/ApiUsage/Logs', [
            'sellerId' => $sellerId,
            'legalEntity' => $payload['legal_entity'],
            'totalRequests' => $payload['total_requests'],
            'uniqueKeys' => $payload['unique_keys'],
            'keysList' => $payload['keys_list'],
            'endpointStats' => $payload['endpoint_stats'],
            'logs' => [
                'data' => $payload['items'],
                'current_page' => $payload['meta']['current_page'],
                'per_page' => $payload['meta']['per_page'],
                'total' => $payload['meta']['total'],
                'last_page' => $payload['meta']['last_page'],
            ],
            'filters' => [
                'date' => $statDate,
                'per_page' => $perPage,
                'endpoint' => $validated['endpoint'] ?? '',
                'method' => $validated['method'] ?? '',
                'response_code' => $validated['response_code'] ?? '',
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