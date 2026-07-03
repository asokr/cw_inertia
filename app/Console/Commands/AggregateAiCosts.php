<?php

namespace App\Console\Commands;

use App\Models\AiCost;
use App\Models\AiRequestLog;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregateAiCosts extends Command
{
    protected $signature = 'ai:aggregate-costs {--date= : Дата в формате YYYY-MM-DD для агрегации только за один день}';

    protected $description = 'Агрегирует расходы AI из ai_request_logs в ai_costs';

    public function handle(): int
    {
        $targetDate = $this->option('date');

        try {
            $rows = $this->collectRows($targetDate);
            $this->persistRows($rows);

            $this->info('Агрегация расходов AI завершена. Записей: ' . count($rows));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            Log::error('AI costs aggregation failed', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
                'date' => $targetDate,
            ]);

            $this->error('Ошибка агрегации расходов AI: ' . $exception->getMessage());

            return self::FAILURE;
        }
    }

    private function collectRows(?string $targetDate): array
    {
        $groups = [];

        AiRequestLog::query()
            ->when($targetDate, static function ($query, $date) {
                $query->whereDate('created_at', $date);
            })
            ->orderBy('id')
            ->chunkById(1000, function ($logs) use (&$groups): void {
                foreach ($logs as $log) {
                    $provider = $this->normalizeProvider((string) ($log->provider ?? ''));
                    if ($provider === null) {
                        continue;
                    }

                    $date = optional($log->created_at)->toDateString();
                    if ($date === null) {
                        continue;
                    }

                    $model = $this->normalizeModel($log->model);
                    $taskType = (string) ($log->task_type ?? 'unknown');

                    $groupKey = implode('|', [
                        $date,
                        $provider,
                        $model ?? '__null__',
                        $taskType,
                    ]);

                    if (! isset($groups[$groupKey])) {
                        $groups[$groupKey] = [
                            'date' => $date,
                            'provider' => $provider,
                            'model' => $model,
                            'task_type' => $taskType,
                            'requests_count' => 0,
                            'input_tokens' => 0,
                            'output_tokens' => 0,
                            'images_count' => 0,
                            'videos_seconds' => 0,
                            'cost' => 0,
                        ];
                    }

                    $inputTokens = $this->resolveInputTokens($log->input_tokens, $log->prompt_tokens, $log->request_payload);
                    $outputTokens = $this->resolveOutputTokens($log->output_tokens, $log->candidates_tokens, $log->response_text);
                    $imagesCount = max(0, (int) ($log->images_count ?? 0));
                    $videosSeconds = $this->resolveVideoSeconds($log);
                    $logCost = $this->calculateLogCost(
                        provider: $provider,
                        model: $model,
                        inputTokens: $inputTokens,
                        outputTokens: $outputTokens,
                        imagesCount: $imagesCount,
                        videosSeconds: $videosSeconds,
                        requestPayload: is_array($log->request_payload) ? $log->request_payload : []
                    );

                    $groups[$groupKey]['requests_count']++;
                    $groups[$groupKey]['input_tokens'] += $inputTokens;
                    $groups[$groupKey]['output_tokens'] += $outputTokens;
                    $groups[$groupKey]['images_count'] += $imagesCount;
                    $groups[$groupKey]['videos_seconds'] += $videosSeconds;
                    $groups[$groupKey]['cost'] += $logCost;
                }
            });

        foreach ($groups as &$group) {
            $group['cost'] = number_format((float) $group['cost'], 6, '.', '');
        }

        return array_values($groups);
    }

    private function persistRows(array $rows): void
    {
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                AiCost::query()->updateOrCreate(
                    [
                        'date' => $row['date'],
                        'provider' => $row['provider'],
                        'model' => $row['model'],
                        'task_type' => $row['task_type'],
                    ],
                    [
                        'requests_count' => $row['requests_count'],
                        'input_tokens' => $row['input_tokens'],
                        'output_tokens' => $row['output_tokens'],
                        'images_count' => $row['images_count'],
                        'videos_seconds' => $row['videos_seconds'],
                        'cost' => $row['cost'],
                    ]
                );
            }
        });
    }

    private function calculateLogCost(
        string $provider,
        ?string $model,
        int $inputTokens,
        int $outputTokens,
        int $imagesCount,
        int $videosSeconds,
        array $requestPayload
    ): float {
        if ($provider === 'gpt') {
            $price = config('ai_pricing.gpt.' . ($model ?? ''), config('ai_pricing.gpt.default', [
                'input' => 0,
                'output' => 0,
            ]));

            return ($inputTokens * (float) Arr::get($price, 'input', 0))
                + ($outputTokens * (float) Arr::get($price, 'output', 0));
        }

        if ($provider === 'gemini') {
            $tokenPrice = config('ai_pricing.gemini.models.' . ($model ?? ''), config('ai_pricing.gemini.default', [
                'input' => 0,
                'output' => 0,
            ]));

            $textCost = ($inputTokens * (float) Arr::get($tokenPrice, 'input', 0))
                + ($outputTokens * (float) Arr::get($tokenPrice, 'output', 0));

            $imagePrice = $this->resolveGeminiImagePricePerImage($requestPayload);
            $imageCost = $imagesCount * $imagePrice;

            return $textCost + $imageCost;
        }

        if ($provider === 'grok') {
            return $videosSeconds * (float) config('ai_pricing.grok.video_per_sec', 0);
        }

        return 0.0;
    }

    private function resolveGeminiImagePricePerImage(array $requestPayload): float
    {
        $resolution = $this->normalizeGeminiResolution((string) data_get($requestPayload, 'resolution', 'default'));
        $perImage = (float) config('ai_pricing.gemini.image.per_image.' . $resolution, 0);

        if ($perImage > 0) {
            return $perImage;
        }

        $basePrice = (float) config('ai_pricing.gemini.image.base_per_image', 0);
        $multiplier = (float) config('ai_pricing.gemini.image.quality_multipliers.' . $resolution, 1);

        return $basePrice * $multiplier;
    }

    private function normalizeGeminiResolution(string $resolution): string
    {
        $normalized = mb_strtolower(trim($resolution));

        return match ($normalized) {
            '1k', '1024', '1024x1024' => '1k',
            '2k', '2048', '2048x2048' => '2k',
            '4k', '4096', '4096x4096' => '4k',
            default => 'default',
        };
    }

    private function normalizeProvider(string $provider): ?string
    {
        $normalized = mb_strtolower(trim($provider));

        return match ($normalized) {
            'gpt', 'openai' => 'gpt',
            'gemini' => 'gemini',
            'grok' => 'grok',
            default => null,
        };
    }

    private function normalizeModel(mixed $model): ?string
    {
        $value = trim((string) ($model ?? ''));

        return $value === '' ? null : $value;
    }

    private function resolveInputTokens(mixed $inputTokens, mixed $promptTokens, mixed $requestPayload): int
    {
        $value = (int) ($inputTokens ?? 0);
        if ($value > 0) {
            return $value;
        }

        $legacy = (int) ($promptTokens ?? 0);
        if ($legacy > 0) {
            return $legacy;
        }

        if (is_array($requestPayload)) {
            $serialized = json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

            return $this->estimateTokensByLength($serialized);
        }

        return 0;
    }

    private function resolveOutputTokens(mixed $outputTokens, mixed $candidateTokens, mixed $responseText): int
    {
        $value = (int) ($outputTokens ?? 0);
        if ($value > 0) {
            return $value;
        }

        $legacy = (int) ($candidateTokens ?? 0);
        if ($legacy > 0) {
            return $legacy;
        }

        return $this->estimateTokensByLength((string) ($responseText ?? ''));
    }

    private function resolveVideoSeconds(AiRequestLog $log): int
    {
        if ($log->limit_consumed_at === null) {
            return 0;
        }

        $secondsFromVideos = 0;
        if (is_array($log->response_videos)) {
            foreach ($log->response_videos as $video) {
                if (! is_array($video)) {
                    continue;
                }

                $secondsFromVideos += max(0, (int) ($video['duration'] ?? 0));
            }
        }

        if ($secondsFromVideos > 0) {
            return $secondsFromVideos;
        }

        if (is_array($log->request_payload)) {
            return max(0, (int) data_get($log->request_payload, 'duration', 0));
        }

        return 0;
    }

    private function estimateTokensByLength(string $text): int
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0;
        }

        return max(1, (int) ceil(mb_strlen($trimmed) / 4));
    }
}
