<?php

namespace App\Http\Controllers\Api\Admin\wb;

use App\Http\Controllers\Controller;
use App\Models\WbApiRequestLog;
use App\Models\WbApiUsageStat;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WbApiUsageStatsController extends Controller
{
    public function index(Request $request)
    {
        $dateInput = $request->input('date');

        try {
            $statDate = $dateInput ? Carbon::parse($dateInput)->toDateString() : now()->toDateString();
        } catch (\Throwable $exception) {
            $statDate = now()->toDateString();
        }

        $perPage = (int) $request->input('per_page', 50);
        $perPage = $perPage > 0 ? $perPage : 50;

        $baseQuery = WbApiUsageStat::query()
            ->where('stat_date', $statDate);

        if ($request->filled('legal_entity')) {
            $baseQuery->where('legal_entity', 'like', '%' . $request->input('legal_entity') . '%');
        }

        if ($request->filled('seller_id')) {
            $baseQuery->where('seller_id', $request->input('seller_id'));
        }

        $totalRequests = (clone $baseQuery)->sum('requests_count');
        $totalKeys = (clone $baseQuery)->count();

        $uniqueSellers = (clone $baseQuery)
            ->whereNotNull('seller_id')
            ->distinct()
            ->count('seller_id');

        $withoutSellerIdCount = (clone $baseQuery)
            ->whereNull('seller_id')
            ->count();

        $uniqueClients = $uniqueSellers + $withoutSellerIdCount;

        $query = (clone $baseQuery)->orderByDesc('requests_count');

        $stats = $query->paginate($perPage);

        $items = collect($stats->items())->map(function (WbApiUsageStat $stat) {
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
        });

        return response()->json([
            'success' => true,
            'messages' => ['Статистика запросов к API Wildberries'],
            'data' => [
                'date' => $statDate,
                'total_requests' => $totalRequests,
                'unique_keys' => $totalKeys,
                'unique_clients' => $uniqueClients,
                'items' => $items,
            ],
            'meta' => [
                'current_page' => $stats->currentPage(),
                'per_page' => $stats->perPage(),
                'total' => $stats->total(),
                'last_page' => $stats->lastPage(),
            ],
        ], 200);
    }

    /**
     * Детальные логи запросов по Seller ID
     */
    public function requestLogs(Request $request, string $sellerId)
    {
        $dateInput = $request->input('date');

        try {
            $statDate = $dateInput ? Carbon::parse($dateInput)->toDateString() : now()->toDateString();
        } catch (\Throwable $exception) {
            $statDate = now()->toDateString();
        }

        $perPage = (int) $request->input('per_page', 50);
        $perPage = min(max($perPage, 10), 100);

        // Получаем информацию о продавце из статистики
        $sellerInfo = WbApiUsageStat::where('seller_id', $sellerId)
            ->where('stat_date', $statDate)
            ->first();

        // Получаем хеши ключей, связанных с этим продавцом за выбранный день
        $hashes = WbApiUsageStat::where('seller_id', $sellerId)
            ->where('stat_date', $statDate)
            ->pluck('api_key_hash')
            ->toArray();

        // Агрегация по эндпоинтам за день
        $startOfDay = Carbon::parse($statDate)->startOfDay();
        $endOfDay = Carbon::parse($statDate)->endOfDay();

        $endpointStats = WbApiRequestLog::whereIn('api_key_hash', $hashes)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->select('endpoint', 'method', DB::raw('COUNT(*) as count'))
            ->groupBy('endpoint', 'method')
            ->orderByDesc('count')
            ->limit(20)
            ->get()
            ->map(fn($item) => [
                'endpoint' => $item->endpoint,
                'method' => $item->method,
                'count' => $item->count,
            ]);

        // Список запросов с пагинацией
        $logsQuery = WbApiRequestLog::whereIn('api_key_hash', $hashes)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->orderByDesc('created_at');

        if ($request->filled('endpoint')) {
            $logsQuery->where('endpoint', 'like', '%' . $request->input('endpoint') . '%');
        }

        if ($request->filled('method')) {
            $logsQuery->where('method', $request->input('method'));
        }

        if ($request->filled('response_code')) {
            $logsQuery->where('response_code', $request->input('response_code'));
        }

        $logs = $logsQuery->paginate($perPage);

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
        });

        // Общее количество запросов за день
        $totalRequests = WbApiRequestLog::whereIn('api_key_hash', $hashes)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->count();

        // Уникальные API ключи
        $uniqueKeys = WbApiRequestLog::whereIn('api_key_hash', $hashes)
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->distinct('api_key_hash')
            ->count('api_key_hash');

        // Список уникальных ключей (из агрегированной статистики)
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
            ->values();

        return response()->json([
            'success' => true,
            'messages' => ['Детальные логи запросов'],
            'data' => [
                'date' => $statDate,
                'seller_id' => $sellerId,
                'legal_entity' => $sellerInfo?->legal_entity,
                'total_requests' => $totalRequests,
                'unique_keys' => $uniqueKeys,
                'keys_list' => $keysList,
                'endpoint_stats' => $endpointStats,
                'items' => $items,
            ],
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ], 200);
    }
}
