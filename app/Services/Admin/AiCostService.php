<?php

namespace App\Services\Admin;

use App\Models\AiCost;
use Illuminate\Support\Facades\Schema;

class AiCostService
{
    /**
     * @return array{total: float, providers: array<int, array{provider: string, cost: float}>}
     */
    public function today(): array
    {
        if (! Schema::hasTable('ai_costs')) {
            return [
                'total' => 0.0,
                'providers' => [],
            ];
        }

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
            ->map(static fn (float $cost, string $provider): array => [
                'provider' => $provider,
                'cost' => round($cost, 6),
            ])
            ->values()
            ->all();

        return [
            'total' => round(array_sum(array_column($providers, 'cost')), 6),
            'providers' => $providers,
        ];
    }

    /**
     * @return array{date_from: string, date_to: string, items: array<int, array<string, float|string>>, totals: array<string, float>}
     */
    public function archive(?string $dateFrom = null, ?string $dateTo = null): array
    {
        if (! Schema::hasTable('ai_costs')) {
            $from = $dateFrom ?: now()->startOfMonth()->toDateString();
            $to = $dateTo ?: now()->endOfMonth()->toDateString();

            return [
                'date_from' => $from,
                'date_to' => $to,
                'items' => [],
                'totals' => [
                    'gpt' => 0.0,
                    'gemini' => 0.0,
                    'grok' => 0.0,
                    'total' => 0.0,
                ],
            ];
        }

        $dateFrom = $dateFrom ?: now()->startOfMonth()->toDateString();
        $dateTo = $dateTo ?: now()->endOfMonth()->toDateString();

        $rows = AiCost::query()
            ->selectRaw('date, provider, SUM(cost) as total_cost')
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->groupBy('date', 'provider')
            ->orderByDesc('date')
            ->get();

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
            if (! in_array($provider, ['gpt', 'gemini', 'grok'], true)) {
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

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'items' => $items,
            'totals' => $totals,
        ];
    }
}