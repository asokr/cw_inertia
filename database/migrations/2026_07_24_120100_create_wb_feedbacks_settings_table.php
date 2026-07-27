<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wb_feedbacks_settings')) {
            return;
        }

        Schema::create('wb_feedbacks_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cabinet_id')->unique();
            $table->string('brands')->nullable();
            $table->boolean('bot_status')->default(false);
            $table->boolean('ai_status')->default(false);
            $table->json('ai_ratings')->nullable();
            $table->string('review_type')->nullable();
            $table->timestamps();

            $table->foreign('cabinet_id')
                ->references('id')
                ->on('wb_cabinets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wb_feedbacks_settings');
    }
};
