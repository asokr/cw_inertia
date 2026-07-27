<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wb_price_calc_v3_data')) {
            return;
        }

        Schema::table('wb_price_calc_v3_data', function (Blueprint $table) {
            try {
                $table->dropForeign(['cabinet_id']);
            } catch (\Throwable) {
                // FK may already be absent on some environments.
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wb_price_calc_v3_data') || ! Schema::hasTable('wb_price_cabinets')) {
            return;
        }

        Schema::table('wb_price_calc_v3_data', function (Blueprint $table) {
            $table->foreign('cabinet_id')
                ->references('id')
                ->on('wb_price_cabinets');
        });
    }
};
