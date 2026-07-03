<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('wb_profitability_items') && !Schema::hasColumn('wb_profitability_items', 'dop_rashod')) {
            Schema::table('wb_profitability_items', function (Blueprint $table) {
                $table->decimal('dop_rashod', 12, 2)->default(0)->after('cost_adjustments');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wb_profitability_items') && Schema::hasColumn('wb_profitability_items', 'dop_rashod')) {
            Schema::table('wb_profitability_items', function (Blueprint $table) {
                $table->dropColumn('dop_rashod');
            });
        }
    }
};
