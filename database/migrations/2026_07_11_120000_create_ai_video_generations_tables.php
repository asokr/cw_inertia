<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_video_generations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscriber_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('title', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_video_generation_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('video_generation_id')->index();
            $table->unsignedBigInteger('subscriber_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('external_request_id', 128)->nullable()->index();
            $table->string('task_type', 64);
            $table->text('prompt');
            $table->unsignedTinyInteger('duration')->default(5);
            $table->string('resolution', 16)->default('480p');
            $table->string('aspect_ratio', 16)->nullable();
            $table->json('source_images')->nullable();
            $table->string('status', 32)->default('pending');
            $table->json('result_video')->nullable();
            $table->text('error_message')->nullable();
            $table->string('model', 128)->nullable();
            $table->timestamp('limit_consumed_at')->nullable();
            $table->timestamps();

            $table->foreign('video_generation_id')
                ->references('id')
                ->on('ai_video_generations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_video_generation_tasks');
        Schema::dropIfExists('ai_video_generations');
    }
};