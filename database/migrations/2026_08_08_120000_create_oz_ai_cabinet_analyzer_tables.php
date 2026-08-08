<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oz_ai_cabinet_analyzer_templates')) {
            Schema::create('oz_ai_cabinet_analyzer_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->longText('system_prompt');
                $table->unsignedInteger('sort_order')->default(100)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->string('response_format', 32)->default('json');
                $table->json('data_sources')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('oz_ai_cabinet_analyzer_reports')) {
            Schema::create('oz_ai_cabinet_analyzer_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cabinet_id')->index();
                $table->string('status', 32)->default('processing')->index();
                $table->string('type', 64)->nullable();
                $table->longText('result_json')->nullable();
                $table->timestamps();

                $table->index('created_at');

                if (Schema::hasTable('oz_cabinets')) {
                    $table->foreign('cabinet_id')
                        ->references('id')
                        ->on('oz_cabinets')
                        ->onDelete('cascade');
                }
            });
        }

        if (! Schema::hasTable('oz_ai_cabinet_analyzer_ai_analyses')) {
            Schema::create('oz_ai_cabinet_analyzer_ai_analyses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('report_id')->index();
                $table->unsignedBigInteger('template_id')->index();
                $table->string('status', 32)->default('processing')->index();
                $table->string('model', 120)->nullable();
                $table->longText('analysis_json')->nullable();
                $table->longText('analysis_text')->nullable();
                $table->longText('analysis_markdown')->nullable();
                $table->unsignedInteger('input_tokens')->default(0);
                $table->unsignedInteger('output_tokens')->default(0);
                $table->unsignedInteger('total_tokens')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->index(['report_id', 'created_at']);

                $table->foreign('report_id')
                    ->references('id')
                    ->on('oz_ai_cabinet_analyzer_reports')
                    ->onDelete('cascade');

                $table->foreign('template_id')
                    ->references('id')
                    ->on('oz_ai_cabinet_analyzer_templates')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oz_ai_cabinet_analyzer_ai_analyses');
        Schema::dropIfExists('oz_ai_cabinet_analyzer_reports');
        Schema::dropIfExists('oz_ai_cabinet_analyzer_templates');
    }
};
