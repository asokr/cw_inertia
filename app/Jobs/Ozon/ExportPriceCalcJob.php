<?php

namespace App\Jobs\Ozon;

use App\Exports\Ozon\PriceCalc\FboFbsExport;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcCabinet;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbo;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbs;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Support\Ozon\PriceCalc\OzonPriceCalcColumns;
use Maatwebsite\Excel\Facades\Excel;

class ExportPriceCalcJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 час

    public function __construct(
        private readonly int $cabinetId,
        private readonly string $type
    ) {}

    public function handle(): void
    {
        try {
            $cabinet = OzPriceCalcCabinet::find($this->cabinetId);
            if (! $cabinet) {
                Log::error("ExportPriceCalcJob: Cabinet {$this->cabinetId} not found");
                return;
            }

            $modelClass = $this->type === 'fbs' ? OzPriceCalcFbs::class : OzPriceCalcFbo::class;

            $query = $modelClass::where('cabinet_id', $this->cabinetId)->orderByDesc('id');
            $rows = $query->get();
            $columns = OzonPriceCalcColumns::forType($this->type);

            // Путь: ozon/price-calc/{cabinetId}/{type}.xlsx
            // С использованием диска public
            $path = "ozon/price-calc/{$this->cabinetId}/{$this->type}.xlsx";

            Excel::store(new FboFbsExport($rows, $columns), $path, 'public');
        } catch (\Throwable $e) {
            Log::error("ExportPriceCalcJob failed for {$this->type} cabinet {$this->cabinetId}: " . $e->getMessage());
            throw $e;
        } finally {
            $cacheKey = sprintf('ozon_price_calc_export_%s_%s', $this->type, $this->cabinetId);
            Cache::forget($cacheKey);
        }
    }
}
