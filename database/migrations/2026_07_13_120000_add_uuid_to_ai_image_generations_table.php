<?php

use App\Models\AiImageGeneration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_image_generations', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        AiImageGeneration::query()
            ->whereNull('uuid')
            ->orderBy('id')
            ->each(function (AiImageGeneration $generation): void {
                $generation->forceFill(['uuid' => (string) Str::uuid()])->save();
            });
    }

    public function down(): void
    {
        Schema::table('ai_image_generations', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};