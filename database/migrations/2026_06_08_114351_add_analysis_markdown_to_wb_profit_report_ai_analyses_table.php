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
        Schema::table('wb_profit_report_ai_analyses', function (Blueprint $table) {
            $table->longText('analysis_markdown')->nullable()->after('analysis_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wb_profit_report_ai_analyses', function (Blueprint $table) {
            $table->dropColumn('analysis_markdown');
        });
    }
};
