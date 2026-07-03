<?php

namespace App\Http\Controllers\Api\Subscriber\Ozon\PriceCalc;

use App\Http\Controllers\Controller;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcCabinet;
use App\Models\Subscribers\Oz\PriceCalc\OzPriceCalcFbo;
use App\Services\Ozon\OzonApiService;
use App\Support\Ozon\PriceCalc\OzonPriceCalcColumns;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Bus;

class FboController extends Controller
{
    public function __construct(private readonly OzonApiService $ozonApiService) {}

    private function hasActiveBatch(string $batchName): bool
    {
        return DB::table('job_batches')
            ->where('name', $batchName)
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->exists();
    }

    public function status(Request $request, int $cabinetId)
    {
        $batchName = sprintf('ozon_fbo_sync_%s', $cabinetId);
        $isSyncing = $this->hasActiveBatch($batchName);

        $cabinet = OzPriceCalcCabinet::where('user_id', $request->user()->id)
            ->find($cabinetId);

        return response()->json([
            'success' => true,
            'data' => [
                'is_syncing' => $isSyncing,
                'last_error' => $cabinet?->last_sync_error,
            ],
        ]);
    }

    public function index(Request $request, int $cabinetId)
    {
        $cabinet = OzPriceCalcCabinet::where('user_id', $request->user()->id)
            ->find($cabinetId);

        if (! $cabinet) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        $perPage = (int) $request->integer('per_page', 25);
        $perPage = max(1, min(100, $perPage));

        $query = OzPriceCalcFbo::where('cabinet_id', $cabinet->id);

        $sortKey = $request->input('sort_key');
        $sortDir = $request->input('sort_dir') === 'desc' ? 'desc' : 'asc';

        if ($sortKey) {
            $query->orderBy($sortKey, $sortDir);
        } else {
            $query->orderByDesc('id');
        }

        $search = trim((string) $request->input('search'));

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('ozon_article', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $items = $query->paginate($perPage);
        $items->setCollection(
            $items->getCollection()->map(static function (OzPriceCalcFbo $item): array {
                $row = $item->toArray();
                $row['updated_at'] = $item->updated_at?->toDateTimeString();

                return $row;
            })
        );

        return response()->json([
            'success' => true,
            'messages' => ['Список номенклатур'],
            'data' => $items,
            'columns' => $this->columnsPayload('fbo'),
        ], 200);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function columnsPayload(string $type): array
    {
        return array_map(static function (array $column): array {
            return [
                'key' => $column['key'],
                'title' => $column['title'],
                'unit' => $column['unit'] ?? '',
                'color' => $column['color'] ?? null,
                'font_color' => $column['font_color'] ?? null,
            ];
        }, OzonPriceCalcColumns::forType($type));
    }

    public function exportStatus(Request $request, int $cabinetId)
    {
        $batchName = sprintf('ozon_fbo_export_%s', $cabinetId);
        $isExporting = $this->hasActiveBatch($batchName);

        $fileUrl = null;
        if (! $isExporting) {
            $path = "ozon/price-calc/{$cabinetId}/fbo.xlsx";
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
                $fileUrl = \Illuminate\Support\Facades\Storage::url($path);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'is_exporting' => $isExporting,
                'file_url' => $fileUrl,
            ],
        ]);
    }

    public function export(Request $request, int $cabinetId)
    {
        $cabinet = OzPriceCalcCabinet::where('user_id', $request->user()->id)
            ->find($cabinetId);

        if (! $cabinet) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        $type = strtolower((string) $request->input('type', 'fbo'));

        if ($type !== 'fbo') {
            return response()->json([
                'success' => false,
                'messages' => ['Поддерживается только экспорт FBO'],
            ], 200);
        }

        $batchName = sprintf('ozon_fbo_export_%s', $cabinet->id);

        if ($this->hasActiveBatch($batchName)) {
            return response()->json([
                'success' => false,
                'messages' => ['Экспорт уже запущен'],
            ], 200);
        }

        Bus::batch([
            new \App\Jobs\Ozon\ExportPriceCalcJob($cabinet->id, 'fbo'),
        ])->name($batchName)->dispatch();

        return response()->json([
            'success' => true,
            'messages' => ['Экспорт запущен фоном'],
        ], 200);
    }

    public function calculateStatus(Request $request, int $cabinetId)
    {
        $batchName = sprintf('ozon_fbo_calc_%s', $cabinetId);
        $isCalculating = $this->hasActiveBatch($batchName);

        return response()->json([
            'success' => true,
            'data' => [
                'is_calculating' => $isCalculating,
            ],
        ]);
    }

    public function calculate(Request $request, int $cabinetId)
    {
        $cabinet = OzPriceCalcCabinet::where('user_id', $request->user()->id)
            ->find($cabinetId);

        if (! $cabinet) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        $batchName = sprintf('ozon_fbo_calc_%s', $cabinet->id);

        if ($this->hasActiveBatch($batchName)) {
            return response()->json([
                'success' => false,
                'messages' => ['Калькуляция уже запущена'],
            ], 200);
        }

        Bus::batch([
            new \App\Jobs\Ozon\CalculatePriceJob($cabinet->id, 'fbo'),
        ])->name($batchName)->dispatch();

        return response()->json([
            'success' => true,
            'messages' => ['Калькуляция запущена фоном'],
        ], 200);
    }

    public function importStatus(Request $request, int $cabinetId)
    {
        $batchName = sprintf('ozon_fbo_import_%s', $cabinetId);
        $isImporting = $this->hasActiveBatch($batchName);

        return response()->json([
            'success' => true,
            'data' => [
                'is_importing' => $isImporting,
            ],
        ]);
    }

    public function import(Request $request, int $cabinetId)
    {
        $cabinet = OzPriceCalcCabinet::where('user_id', $request->user()->id)
            ->find($cabinetId);

        if (! $cabinet) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'messages' => $validator->errors()->all(),
            ], 200);
        }

        $batchName = sprintf('ozon_fbo_import_%s', $cabinet->id);

        if ($this->hasActiveBatch($batchName)) {
            return response()->json([
                'success' => false,
                'messages' => ['Импорт уже запущен'],
            ], 200);
        }

        $file = $request->file('file');
        $filename = 'import_fbo_' . $cabinet->id . '_' . time() . '.xlsx';
        $path = $file->storeAs('imports', $filename); // storage/app/imports
        $absolutePath = \Illuminate\Support\Facades\Storage::path($path);

        Bus::batch([
            new \App\Jobs\Ozon\ImportPriceCalcJob($cabinet->id, 'fbo', $absolutePath),
        ])->name($batchName)->dispatch();

        return response()->json([
            'success' => true,
            'messages' => ['Импорт запущен фоном'],
        ], 200);
    }

    public function sync(Request $request, int $cabinetId)
    {
        $cabinet = OzPriceCalcCabinet::where('user_id', $request->user()->id)
            ->find($cabinetId);

        if (! $cabinet) {
            return response()->json([
                'success' => false,
                'messages' => ['Такого кабинета нет'],
            ], 200);
        }

        $batchName = sprintf('ozon_fbo_sync_%s', $cabinet->id);

        if ($this->hasActiveBatch($batchName)) {
            return response()->json([
                'success' => false,
                'messages' => ['Синхронизация уже запущена'],
            ], 200);
        }

        Bus::batch([
            new \App\Jobs\Ozon\SyncPriceCalcJob($cabinet->id, 'fbo'),
        ])->name($batchName)->dispatch();

        return response()->json([
            'success' => true,
            'messages' => ['Синхронизация запущена фоном'],
        ], 200);
    }
}
