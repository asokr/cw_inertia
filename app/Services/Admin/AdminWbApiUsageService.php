<?php

namespace App\Services\Admin;

use App\Models\WbApiRequestLog;
use App\Models\WbApiUsageStat;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AdminWbApiUsageService
{
    public function paginateStats(string $statDate, ?string $legalEntity, ?string $sellerId, int $perPage = 50): LengthAwarePaginator
    {
        $baseQuery = WbApiUsageStat::query()->where('stat_date', $statDate);

        if ($legalEntity) {
            $baseQuery->where('legal_entity', 'like', '%' . $legalEntity . '%');
        }

        if ($sellerId) {
            $baseQuery->where('seller_id', $sellerId);
        }

        return (clone $baseQuery)
            ->orderByDesc('requests_count')
            ->paginate($perPage);
    }

    /**
     * @return array{total_requests: int, unique_keys: int, unique_clients: int}
     */
    public function summaryForDate(string $statDate, ?string $legalEntity = null, ?string $sellerId = null): array
    {
        $baseQuery = WbApiUsageStat::query()->where('stat_date', $statDate);

        if ($legalEntity) {
            $baseQuery->where('legal_entity', 'like', '%' . $legalEntity . '%');
        }

        if ($sellerId) {
            $baseQuery->where('seller_id', $sellerId);
        }

        $uniqueSellers = (clone $baseQuery)
            ->whereNotNull('seller_id')
            ->distinct()
            ->count('seller_id');

        $withoutSellerIdCount = (clone $baseQuery)
            ->whereNull('seller_id')
            ->count();

        return [
            'total_requests' => (int) (clone $baseQuery)->sum('requests_count'),
            'unique_keys' => (int) (clone $baseQuery)->count(),
            'unique_clients' => $uniqueSellers + $withoutSellerIdCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sellerLogs(
        string $sellerId,
        string $statDate,
        array $filters,
        int $perPage = 25,
        int $page = 1,
    ): array {
        $sellerInfo = WbApiUsageStat::where('seller_id', $sellerId)
            ->where('stat_date', $statDate)
            ->first();

        $hashes = WbApiUsageStat::where('seller_id', $sellerId)
            ->where('stat_date', $statDate)
            ->pluck('api_key_hash')
            ->toArray();

        $startOfDay = Carbon::parse($statDate)->startOfDay();
        $endOfDay = Carbon::parse($statDate)->endOfDay();

        $endpointStats = WbApiRequestLog::whereIn('api_key_hash', $hashes)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->select('endpoint', 'method', DB::raw('COUNT(*) as count'))
            ->groupBy('endpoint', 'method')
            ->orderByDesc('count')
            ->limit(20)
            ->get()
            ->map(fn ($item) => [
                'endpoint' => $item->endpoint,
                'method' => $item->method,
                'count' => $item->count,
            ])
            ->all();

        $logsQuery = WbApiRequestLog::whereIn('api_key_hash', $hashes)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->orderByDesc('created_at');

        if (! empty($filters['endpoint'])) {
            $logsQuery->where('endpoint', 'like', '%' . $filters['endpoint'] . '%');
        }

        if (! empty($filters['method'])) {
            $logsQuery->where('method', $filters['method']);
        }

        if (! empty($filters['response_code'])) {
            $logsQuery->where('response_code', $filters['response_code']);
        }

        $logs = $logsQuery->paginate($perPage, ['*'], 'page', $page);

        $items = collect($logs->items())->map(function (WbApiRequestLog $log) {
            return [
                'id' => $log->id,
                'api_key' => $log->api_key,
                'method' => $log->method,
                'endpoint' => $log->endpoint,
                'request_data' => $log->request_data,
                'response_code' => $log->response_code,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ];
        })->all();

        $totalRequests = WbApiRequestLog::whereIn('api_key_hash', $hashes)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->count();

        $uniqueKeys = WbApiRequestLog::whereIn('api_key_hash', $hashes)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->distinct('api_key_hash')
            ->count('api_key_hash');

        $keysList = WbApiUsageStat::where('seller_id', $sellerId)
            ->where('stat_date', $statDate)
            ->get()
            ->map(function ($stat) {
                $key = $stat->api_key;
                if (empty($key)) {
                    return null;
                }
                if (strlen($key) <= 10) {
                    return $key;
                }

                return substr($key, 0, 5) . '.....' . substr($key, -5);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'date' => $statDate,
            'seller_id' => $sellerId,
            'legal_entity' => $sellerInfo?->legal_entity,
            'total_requests' => $totalRequests,
            'unique_keys' => $uniqueKeys,
            'keys_list' => $keysList,
            'endpoint_stats' => $endpointStats,
            'items' => $items,
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ];
    }

    public function formatStatRow(WbApiUsageStat $stat): array
    {
        return [
            'id' => $stat->id,
            'stat_date' => $stat->stat_date->toDateString(),
            'api_key' => $stat->api_key,
            'api_key_hash' => $stat->api_key_hash,
            'requests_count' => $stat->requests_count,
            'legal_entity' => $stat->legal_entity,
            'seller_id' => $stat->seller_id && trim($stat->seller_id) !== '' ? trim($stat->seller_id) : null,
            'legal_entity_synced_at' => optional($stat->legal_entity_synced_at)->toDateTimeString(),
            'updated_at' => optional($stat->updated_at)->toDateTimeString(),
        ];
    }
}