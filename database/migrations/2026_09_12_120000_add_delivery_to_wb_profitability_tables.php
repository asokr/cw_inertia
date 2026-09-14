<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wb_profitability_items') && ! Schema::hasColumn('wb_profitability_items', 'delivery')) {
            Schema::table('wb_profitability_items', function (Blueprint $table) {
                $table->decimal('delivery', 14, 2)->default(0);
            });
        }

        if (Schema::hasTable('wb_profitability_reports') && ! Schema::hasColumn('wb_profitability_reports', 'delivery')) {
            Schema::table('wb_profitability_reports', function (Blueprint $table) {
                $table->decimal('delivery', 14, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wb_profitability_items') && Schema::hasColumn('wb_profitability_items', 'delivery')) {
            Schema::table('wb_profitability_items', function (Blueprint $table) {
                $table->dropColumn('delivery');
            });
        }

        if (Schema::hasTable('wb_profitability_reports') && Schema::hasColumn('wb_profitability_reports', 'delivery')) {
            Schema::table('wb_profitability_reports', function (Blueprint $table) {
                $table->dropColumn('delivery');
            });
        }
    }
};
