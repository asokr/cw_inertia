<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wb_profit_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cabinet_id')->index();
            $table->string('status', 32)->default('processing')->index();
            $table->string('type', 64)->nullable();
            $table->json('result_json')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->foreign('cabinet_id')->references('id')->on('wb_profit_analyzer_cabinets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_profit_reports');
    }
};
