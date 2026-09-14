<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wb_profitability_reports') && ! Schema::hasColumn('wb_profitability_reports', 'return_compensation')) {
            Schema::table('wb_profitability_reports', function (Blueprint $table) {
                $table->decimal('return_compensation', 14, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wb_profitability_reports') && Schema::hasColumn('wb_profitability_reports', 'return_compensation')) {
            Schema::table('wb_profitability_reports', function (Blueprint $table) {
                $table->dropColumn('return_compensation');
            });
        }
    }
};
