<?php

namespace App\Http\Controllers\Api\Admin\services\ai;

use App\Http\Controllers\Controller;
use App\Models\AiCost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminAiCostsController extends Controller
{
    public function today(): JsonResponse
    {
        $today = now()->toDateString();

        $providerRows = AiCost::query()
            ->selectRaw('provider, SUM(cost) as total_cost')
            ->whereDate('date', $today)
            ->groupBy('provider')
            ->get();

        $providersMap = [
            'gpt' => 0.0,
            'gemini' => 0.0,
            'grok' => 0.0,
        ];

        foreach ($providerRows as $row) {
            $provider = (string) ($row->provider ?? '');
            if (! array_key_exists($provider, $providersMap)) {
                continue;
            }

            $providersMap[$provider] = (float) ($row->total_cost ?? 0);
        }

        $providers = collect($providersMap)
            ->map(static fn(float $cost, string $provider): array => [
                'provider' => $provider,
                'cost' => round($cost, 6),
            ])
            ->values()
            ->all();

        $total = round(array_sum(array_column($providers, 'cost')), 6);

        return response()->json([
            'success' => true,
            'messages' => ['Агрегированные расходы AI за сегодня получены'],
            'data' => [
                'total' => $total,
                'providers' => $providers,
            ],
        ], 200);
    }

    public function archive(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
                'data' => null,
            ], 200);
        }

        $dateFrom = (string) ($request->input('date_from') ?: now()->startOfMonth()->toDateString());
        $dateTo = (string) ($request->input('date_to') ?: now()->endOfMonth()->toDateString());

        $rows = AiCost::query()
            ->selectRaw('date, provider, SUM(cost) as total_cost')
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->groupBy('date', 'provider')
            ->orderByDesc('date')
            ->get();

        $providersBase = [
            'gpt' => 0.0,
            'gemini' => 0.0,
            'grok' => 0.0,
        ];

        $days = [];
        foreach ($rows as $row) {
            $date = optional($row->date)->format('Y-m-d');
            if (! $date) {
                continue;
            }

            if (! isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'gpt' => 0.0,
                    'gemini' => 0.0,
                    'grok' => 0.0,
                    'total' => 0.0,
                ];
            }

            $provider = (string) ($row->provider ?? '');
            if (! array_key_exists($provider, $providersBase)) {
                continue;
            }

            $days[$date][$provider] = round((float) ($row->total_cost ?? 0), 6);
        }

        $items = array_values($days);
        foreach ($items as &$item) {
            $item['total'] = round(
                (float) $item['gpt'] + (float) $item['gemini'] + (float) $item['grok'],
                6
            );
        }

        $totals = [
            'gpt' => 0.0,
            'gemini' => 0.0,
            'grok' => 0.0,
            'total' => 0.0,
        ];

        foreach ($items as $item) {
            $totals['gpt'] += (float) ($item['gpt'] ?? 0);
            $totals['gemini'] += (float) ($item['gemini'] ?? 0);
            $totals['grok'] += (float) ($item['grok'] ?? 0);
            $totals['total'] += (float) ($item['total'] ?? 0);
        }

        foreach ($totals as $key => $value) {
            $totals[$key] = round($value, 6);
        }

        return response()->json([
            'success' => true,
            'messages' => ['Архив расходов AI получен'],
            'data' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'items' => $items,
                'totals' => $totals,
            ],
        ], 200);
    }
}
