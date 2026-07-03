<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Throwable;

class WbPriceCalculationV2ToV3Seeder extends Seeder
{
    public function run(): void
    {
        $processed = 0;
        $inserted = 0;
        $failedBatches = 0;

        $columns = [
            'cabinet_id',
            'brand',
            'subject_name',
            'vendor_code',
            'size',
            'barcode',
            'nm_id',
            'volume_liters',
            'extra_liters',
            'cost_price',
            'margin_percent',
            'fulfillment_fee',
            'maintenance_percent',
            'stop_price',
            'avg_base_logistics',
            'avg_extra_liter_logistics',
            'localization_index',
            'avg_logistics',
            'return_cost',
            'buyout_percent',
            'total_logistics',
            'storage_cost',
            'sales_count',
            'storage_per_sale',
            'advertising_percent',
            'wb_commission_percent',
            'acquiring_percent',
            'tax_percent',
            'commission_plus_acquiring',
            'standard_discount_percent',
            'promotion_percent',
            'min_price_promo',
            'standard_price',
            'price_before_discount',
            'created_at',
            'updated_at',
        ];

        DB::table('wb_price_calc_v2_data')
            ->whereNull('deleted_at')
            ->select(array_merge(['id'], $columns))
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$processed, &$inserted, &$failedBatches, $columns): void {
                $processed += $rows->count();

                $payload = [];
                foreach ($rows as $row) {
                    if ($this->existsInV3($row)) {
                        continue;
                    }

                    $record = [];
                    foreach ($columns as $column) {
                        $record[$column] = $row->{$column};
                    }
                    $payload[] = $record;
                }

                if (empty($payload)) {
                    return;
                }

                try {
                    DB::transaction(function () use ($payload, &$inserted): void {
                        DB::table('wb_price_calc_v3_data')->insert($payload);
                        $inserted += count($payload);
                    });
                } catch (Throwable $exception) {
                    $failedBatches++;

                    $this->command?->error('Ошибка вставки батча V2 -> V3: ' . $exception->getMessage());
                }
            });

        $this->command?->info('Перенос V2 -> V3 завершен.');
        $this->command?->info('Обработано строк V2: ' . $processed);
        $this->command?->info('Вставлено строк V3: ' . $inserted);
        $this->command?->info('Батчей с ошибкой: ' . $failedBatches);
    }

    private function existsInV3(object $row): bool
    {
        $query = DB::table('wb_price_calc_v3_data')
            ->where('cabinet_id', $row->cabinet_id)
            ->where('nm_id', $row->nm_id);

        if ($row->barcode !== null) {
            $query->where('barcode', $row->barcode);
        } else {
            $query->whereNull('barcode');
        }

        if ($row->vendor_code !== null) {
            $query->where('vendor_code', $row->vendor_code);
        } else {
            $query->whereNull('vendor_code');
        }

        if ($row->size !== null) {
            $query->where('size', $row->size);
        } else {
            $query->whereNull('size');
        }

        return $query->exists();
    }
}