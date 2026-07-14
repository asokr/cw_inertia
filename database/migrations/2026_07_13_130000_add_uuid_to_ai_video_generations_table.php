<?php

use App\Models\AiVideoGeneration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_video_generations', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
        });

        AiVideoGeneration::query()
            ->whereNull('uuid')
            ->orderBy('id')
            ->each(function (AiVideoGeneration $generation): void {
                $generation->forceFill(['uuid' => (string) Str::uuid()])->save();
            });
    }

    public function down(): void
    {
        Schema::table('ai_video_generations', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};