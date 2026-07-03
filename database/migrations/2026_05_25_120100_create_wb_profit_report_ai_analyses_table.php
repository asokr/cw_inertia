<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wb_profit_report_ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('report_id')->index();
            $table->unsignedBigInteger('template_id')->index();
            $table->string('status', 32)->default('processing')->index();
            $table->string('model', 120)->nullable();
            $table->longText('analysis_json')->nullable();
            $table->longText('analysis_text')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['report_id', 'created_at']);
            $table->foreign('report_id')->references('id')->on('wb_profit_reports')->onDelete('cascade');
            $table->foreign('template_id')->references('id')->on('wb_profit_analysis_templates')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_profit_report_ai_analyses');
    }
};
