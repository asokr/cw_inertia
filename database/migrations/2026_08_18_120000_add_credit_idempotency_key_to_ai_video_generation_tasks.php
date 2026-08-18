<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('ai_video_generation_tasks')
            || Schema::hasColumn('ai_video_generation_tasks', 'credit_idempotency_key')
        ) {
            return;
        }

        Schema::table('ai_video_generation_tasks', function (Blueprint $table) {
            $table->string('credit_idempotency_key')->nullable()->after('limit_consumed_at');
        });
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('ai_video_generation_tasks')
            || ! Schema::hasColumn('ai_video_generation_tasks', 'credit_idempotency_key')
        ) {
            return;
        }

        Schema::table('ai_video_generation_tasks', function (Blueprint $table) {
            $table->dropColumn('credit_idempotency_key');
        });
    }
};
