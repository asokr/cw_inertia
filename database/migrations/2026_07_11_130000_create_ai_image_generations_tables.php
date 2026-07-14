<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_image_generations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('subscriber_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('title', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('ai_image_generation_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('image_generation_id')->index();
            $table->unsignedBigInteger('subscriber_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('task_type', 64);
            $table->text('prompt');
            $table->unsignedTinyInteger('image_variants')->default(1);
            $table->string('resolution', 16)->default('default');
            $table->string('aspect_ratio', 16)->nullable();
            $table->json('source_images')->nullable();
            $table->string('status', 32)->default('done');
            $table->json('result_images')->nullable();
            $table->text('error_message')->nullable();
            $table->string('model', 128)->nullable();
            $table->timestamp('limit_consumed_at')->nullable();
            $table->timestamps();

            $table->foreign('image_generation_id')
                ->references('id')
                ->on('ai_image_generations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_image_generation_tasks');
        Schema::dropIfExists('ai_image_generations');
    }
};