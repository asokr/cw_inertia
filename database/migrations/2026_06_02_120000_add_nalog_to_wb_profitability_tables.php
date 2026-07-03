<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('wb_profitability_items') && !Schema::hasColumn('wb_profitability_items', 'nalog')) {
            Schema::table('wb_profitability_items', function (Blueprint $table) {
                $table->decimal('nalog', 12, 2)->default(0);
            });
        }

        if (Schema::hasTable('wb_profitability_reports')) {
            Schema::table('wb_profitability_reports', function (Blueprint $table) {
                if (!Schema::hasColumn('wb_profitability_reports', 'nalog')) {
                    $table->decimal('nalog', 12, 2)->default(0);
                }

                if (!Schema::hasColumn('wb_profitability_reports', 'nalog_percent')) {
                    $table->decimal('nalog_percent', 5, 2)->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wb_profitability_items') && Schema::hasColumn('wb_profitability_items', 'nalog')) {
            Schema::table('wb_profitability_items', function (Blueprint $table) {
                $table->dropColumn('nalog');
            });
        }

        if (Schema::hasTable('wb_profitability_reports')) {
            Schema::table('wb_profitability_reports', function (Blueprint $table) {
                if (Schema::hasColumn('wb_profitability_reports', 'nalog_percent')) {
                    $table->dropColumn('nalog_percent');
                }

                if (Schema::hasColumn('wb_profitability_reports', 'nalog')) {
                    $table->dropColumn('nalog');
                }
            });
        }
    }
};
