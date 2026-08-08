<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Ozon cabinets: drop legacy price-calc tool cabinet data (no data migration).
 * Managers recreate cabinets manually; FBO/FBS re-sync after that.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'oz_price_calc_fbo',
            'oz_price_calc_fbs',
            'oz_price_calc_cabinets',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                DB::table($table)->delete();
            } catch (\Throwable) {
                // Best-effort cleanup; schema differences must not block deploy.
            }
        }
    }

    public function down(): void
    {
        // Irreversible data cleanup.
    }
};
