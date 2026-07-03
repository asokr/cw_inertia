<?php

namespace App\Jobs\Ozon;

use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcCabinet;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbo;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbs;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Support\Ozon\PriceCalc\OzonPriceCalcColumns;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ImportPriceCalcJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 час

    public function __construct(
        private readonly int $cabinetId,
        private readonly string $type,
        private readonly string $filePath
    ) {}

    public function handle(): void
    {
        try {
            $cabinet = OzPriceCalcCabinet::find($this->cabinetId);
            if (! $cabinet) {
                Log::error("ImportPriceCalcJob: Cabinet {$this->cabinetId} not found");
                return;
            }

            clearstatcache(true, $this->filePath);

            if (! file_exists($this->filePath)) {
                Log::error("ImportPriceCalcJob: File {$this->filePath} not found");
                return;
            }

            $sheets = Excel::toArray([], $this->filePath);
            $rows = $sheets[0] ?? [];

            if (count($rows) < 5) {
                Log::warning("ImportPriceCalcJob: Empty rows for cabinet {$this->cabinetId}");
                return;
            }

            $columns = OzonPriceCalcColumns::forType($this->type);
            $allowedKeys = $this->getAllowedKeys($this->type);
            $headerMap = $this->buildHeaderMap($rows, $columns);

            if (empty($headerMap['barcode'])) {
                Log::warning("ImportPriceCalcJob: Barcode column not found for cabinet {$this->cabinetId}");
                return;
            }

            $updated = 0;
            $modelClass = $this->type === 'fbs' ? OzPriceCalcFbs::class : OzPriceCalcFbo::class;

            DB::transaction(function () use ($rows, $columns, $allowedKeys, $headerMap, $cabinet, &$updated, $modelClass) {
                $dataRows = array_slice($rows, 4);

                foreach ($dataRows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $mapped = [];

                    foreach ($columns as $column) {
                        $columnIndex = $headerMap[$column['key']] ?? null;
                        if ($columnIndex === null) {
                            continue;
                        }

                        $mapped[$column['key']] = $row[$columnIndex] ?? null;
                    }

                    $barcode = trim((string) ($mapped['barcode'] ?? ''));

                    if ($barcode === '') {
                        continue;
                    }

                    $model = $modelClass::where('cabinet_id', $cabinet->id)
                        ->where('barcode', $barcode)
                        ->first();

                    if (! $model) {
                        continue;
                    }

                    $payload = [];

                    foreach ($allowedKeys as $key) {
                        if (! array_key_exists($key, $mapped)) {
                            continue;
                        }

                        $value = $mapped[$key];

                        if ($value === '' || $value === null) {
                            $value = null;
                        } elseif (is_numeric($value)) {
                            $value = (float) $value;
                        } else {
                            $value = -1;
                        }

                        $payload[$key] = $value;
                    }

                    if (! empty($payload)) {
                        $model->fill($payload);

                        // При изменении габаритов пересчитываем объем
                        if (isset($payload['length_cm']) || isset($payload['width_cm']) || isset($payload['height_cm'])) {
                            $l = (float) $model->length_cm;
                            $w = (float) $model->width_cm;
                            $h = (float) $model->height_cm;
                            $model->volume_liters = round(($l * $w * $h) / 1000, 5);
                        }

                        $model->save();
                        $updated++;
                    }
                }
            });

            // После импорта сразу пересчитываем значения, чтобы фронт получил актуальные расчеты.
            (new CalculatePriceJob($this->cabinetId, $this->type))->handle();
        } catch (\Throwable $e) {
            Log::error("ImportPriceCalcJob failed for {$this->type} cabinet {$this->cabinetId}: " . $e->getMessage());
            throw $e;
        } finally {
            if (file_exists($this->filePath)) {
                @unlink($this->filePath);
            }
            $cacheKey = sprintf('ozon_price_calc_import_%s_%s', $this->type, $this->cabinetId);
            Cache::forget($cacheKey);
        }
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, array<string, mixed>> $columns
     * @return array<string, int>
     */
    private function buildHeaderMap(array $rows, array $columns): array
    {
        $headerRow = $rows[1] ?? [];
        $map = [];

        foreach ($columns as $column) {
            $normalizedNeedles = [$this->normalizeHeader((string) ($column['title'] ?? ''))];

            foreach (($column['aliases'] ?? []) as $alias) {
                $normalizedNeedles[] = $this->normalizeHeader((string) $alias);
            }

            $normalizedNeedles = array_values(array_filter(array_unique($normalizedNeedles)));

            foreach ($headerRow as $index => $headerCell) {
                $normalizedHeader = $this->normalizeHeader((string) $headerCell);
                if ($normalizedHeader === '') {
                    continue;
                }

                if (in_array($normalizedHeader, $normalizedNeedles, true)) {
                    $map[$column['key']] = (int) $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function getAllowedKeys(string $type): array
    {
        $columns = OzonPriceCalcColumns::forType($type);
        $allowed = [];

        foreach ($columns as $column) {
            if (($column['mode'] ?? '') !== 'заполняется') {
                continue;
            }

            $allowed[] = $column['key'];
        }

        return $allowed;
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace('ё', 'е', $value);
        $value = str_replace(["\n", "\r", "\t"], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }
}
