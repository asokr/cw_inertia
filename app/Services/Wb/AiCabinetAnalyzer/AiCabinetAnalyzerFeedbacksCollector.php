<?php

namespace App\Services\Wb\AiCabinetAnalyzer;

use App\Http\Traits\GuzzleTrait;
use App\Http\Traits\WBFeedbacksTrait;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiCabinetAnalyzerFeedbacksCollector
{
    use GuzzleTrait;
    use WBFeedbacksTrait;

    private const MAX_TAKE = 5000;
    private const REQUEST_INTERVAL_MS = 333000;

    /**
     * @param  int[]  $nmids
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int|bool>, failed: bool}
     */
    public function collect(string $apiKey, string $beginDate, string $endDate, array $nmids): array
    {
        $nmids = array_values(array_unique(array_filter(array_map('intval', $nmids), static fn(int $nmid): bool => $nmid > 0)));
        if ($nmids === []) {
            return $this->emptyResult();
        }

        $nmidsLookup = array_fill_keys($nmids, true);
        $begin = Carbon::parse($beginDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        try {
            $unanswered = $this->fetchFeedbacksPage($apiKey, false);
            usleep(self::REQUEST_INTERVAL_MS);
            $answered = $this->fetchFeedbacksPage($apiKey, true);

            $merged = $this->mergeUniqueById(array_merge($unanswered, $answered));
            $filtered = $this->filterByPeriodAndNmids($merged, $begin, $end, $nmidsLookup);
            $sorted = $this->sortByCreatedDateDesc($filtered);

            return [
                'items' => $sorted,
                'meta' => [
                    'fetched_answered' => count($answered),
                    'fetched_unanswered' => count($unanswered),
                    'merged_unique' => count($merged),
                    'in_period_for_nmids' => count($sorted),
                    'request_count' => 2,
                ],
                'failed' => false,
            ];
        } catch (Throwable $e) {
            Log::warning('[AiCabinetAnalyzer] Ошибка сбора отзывов WB', [
                'begin_date' => $beginDate,
                'end_date' => $endDate,
                'nmids_count' => count($nmids),
                'error' => $e->getMessage(),
            ]);

            return $this->emptyResult(failed: true);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFeedbacksPage(string $apiKey, bool $isAnswered): array
    {
        $response = $this->parseApiResponse($this->apiGetFeedbacks($apiKey, [
            'isAnswered' => $isAnswered,
            'take' => self::MAX_TAKE,
            'skip' => 0,
        ]));

        if (!$response['success']) {
            $message = is_string($response['data'])
                ? $response['data']
                : ('Код ' . (int) $response['code'] . ' при запросе отзывов WB');

            throw new \RuntimeException($message);
        }

        $feedbacks = data_get($response, 'data.data.feedbacks', data_get($response, 'data.feedbacks', []));

        return is_array($feedbacks) ? array_values($feedbacks) : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $feedbacks
     * @return array<int, array<string, mixed>>
     */
    private function mergeUniqueById(array $feedbacks): array
    {
        $map = [];

        foreach ($feedbacks as $feedback) {
            if (!is_array($feedback)) {
                continue;
            }

            $id = (string) ($feedback['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $map[$id] = $feedback;
        }

        return array_values($map);
    }

    /**
     * @param  array<int, array<string, mixed>>  $feedbacks
     * @param  array<int, true>  $nmidsLookup
     * @return array<int, array<string, mixed>>
     */
    private function filterByPeriodAndNmids(array $feedbacks, Carbon $begin, Carbon $end, array $nmidsLookup): array
    {
        $result = [];

        foreach ($feedbacks as $feedback) {
            $createdAt = $this->parseCreatedDate((string) ($feedback['createdDate'] ?? ''));
            if ($createdAt === null || !$createdAt->betweenIncluded($begin, $end)) {
                continue;
            }

            $nmid = $this->extractNmid($feedback);
            if ($nmid <= 0 || !isset($nmidsLookup[$nmid])) {
                continue;
            }

            $result[] = $feedback;
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $feedbacks
     * @return array<int, array<string, mixed>>
     */
    private function sortByCreatedDateDesc(array $feedbacks): array
    {
        usort($feedbacks, static function (array $left, array $right): int {
            $leftTs = strtotime((string) ($left['createdDate'] ?? '')) ?: 0;
            $rightTs = strtotime((string) ($right['createdDate'] ?? '')) ?: 0;

            return $rightTs <=> $leftTs;
        });

        return array_values($feedbacks);
    }

    private function parseCreatedDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $feedback
     */
    private function extractNmid(array $feedback): int
    {
        return (int) (
            Arr::get($feedback, 'productDetails.nmId')
            ?? Arr::get($feedback, 'productDetails.nmID')
            ?? Arr::get($feedback, 'productDetails.nmid')
            ?? 0
        );
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int|bool>, failed: bool}
     */
    private function emptyResult(bool $failed = false): array
    {
        return [
            'items' => [],
            'meta' => [
                'fetched_answered' => 0,
                'fetched_unanswered' => 0,
                'merged_unique' => 0,
                'in_period_for_nmids' => 0,
                'request_count' => 0,
            ],
            'failed' => $failed,
        ];
    }
}