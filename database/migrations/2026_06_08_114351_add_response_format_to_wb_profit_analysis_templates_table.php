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
        Schema::table('wb_profit_analysis_templates', function (Blueprint $table) {
            $table->enum('response_format', ['json', 'markdown'])->default('json')->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wb_profit_analysis_templates', function (Blueprint $table) {
            $table->dropColumn('response_format');
        });
    }
};
