<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('wb_profitability_items')) {
            Schema::table('wb_profitability_items', function (Blueprint $table) {
                if (!Schema::hasColumn('wb_profitability_items', 'cashback')) {
                    $table->decimal('cashback', 12, 2)->default(0)->after('cost_adjustments');
                }
            });
        }

        if (Schema::hasTable('wb_profitability_reports')) {
            Schema::table('wb_profitability_reports', function (Blueprint $table) {
                if (!Schema::hasColumn('wb_profitability_reports', 'cashback')) {
                    $table->decimal('cashback', 12, 2)->default(0)->after('acceptance');
                }

                if (!Schema::hasColumn('wb_profitability_reports', 'dop_rashod')) {
                    $table->decimal('dop_rashod', 12, 2)->default(0)->after('cashback');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wb_profitability_items')) {
            Schema::table('wb_profitability_items', function (Blueprint $table) {
                if (Schema::hasColumn('wb_profitability_items', 'cashback')) {
                    $table->dropColumn('cashback');
                }
            });
        }

        if (Schema::hasTable('wb_profitability_reports')) {
            Schema::table('wb_profitability_reports', function (Blueprint $table) {
                if (Schema::hasColumn('wb_profitability_reports', 'dop_rashod')) {
                    $table->dropColumn('dop_rashod');
                }

                if (Schema::hasColumn('wb_profitability_reports', 'cashback')) {
                    $table->dropColumn('cashback');
                }
            });
        }
    }
};
