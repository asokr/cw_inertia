<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('oz_price_calc_fbo')) {
            return;
        }

        Schema::table('oz_price_calc_fbo', function (Blueprint $table) {
            if (! Schema::hasColumn('oz_price_calc_fbo', 'dopakovka_rub')) {
                $table->double('dopakovka_rub')->nullable()->after('price_markup_for_logistics_percent');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('oz_price_calc_fbo')) {
            return;
        }

        Schema::table('oz_price_calc_fbo', function (Blueprint $table) {
            if (Schema::hasColumn('oz_price_calc_fbo', 'dopakovka_rub')) {
                $table->dropColumn('dopakovka_rub');
            }
        });
    }
};
