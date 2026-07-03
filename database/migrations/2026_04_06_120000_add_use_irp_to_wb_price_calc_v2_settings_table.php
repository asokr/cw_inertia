<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('wb_price_calc_v2_settings')) {
            return;
        }

        if (!Schema::hasColumn('wb_price_calc_v2_settings', 'use_irp')) {
            Schema::table('wb_price_calc_v2_settings', function (Blueprint $table) {
                $table->boolean('use_irp')->default(false)->after('use_storage');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('wb_price_calc_v2_settings')) {
            return;
        }

        if (Schema::hasColumn('wb_price_calc_v2_settings', 'use_irp')) {
            Schema::table('wb_price_calc_v2_settings', function (Blueprint $table) {
                $table->dropColumn('use_irp');
            });
        }
    }
};
