<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('wb_ai_cabinet_analyzer_ai_analyses')
            && ! Schema::hasColumn('wb_ai_cabinet_analyzer_ai_analyses', 'provider')
        ) {
            Schema::table('wb_ai_cabinet_analyzer_ai_analyses', function (Blueprint $table) {
                $table->string('provider', 32)->nullable()->after('model');
                $table->unsignedInteger('credits_charged')->nullable()->after('total_tokens');
                $table->json('billing_snapshot')->nullable()->after('credits_charged');
            });
        }

        if (
            Schema::hasTable('oz_ai_cabinet_analyzer_ai_analyses')
            && ! Schema::hasColumn('oz_ai_cabinet_analyzer_ai_analyses', 'provider')
        ) {
            Schema::table('oz_ai_cabinet_analyzer_ai_analyses', function (Blueprint $table) {
                $table->string('provider', 32)->nullable()->after('model');
                $table->unsignedInteger('credits_charged')->nullable()->after('total_tokens');
                $table->json('billing_snapshot')->nullable()->after('credits_charged');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('wb_ai_cabinet_analyzer_ai_analyses')
            && Schema::hasColumn('wb_ai_cabinet_analyzer_ai_analyses', 'provider')
        ) {
            Schema::table('wb_ai_cabinet_analyzer_ai_analyses', function (Blueprint $table) {
                $table->dropColumn(['provider', 'credits_charged', 'billing_snapshot']);
            });
        }

        if (
            Schema::hasTable('oz_ai_cabinet_analyzer_ai_analyses')
            && Schema::hasColumn('oz_ai_cabinet_analyzer_ai_analyses', 'provider')
        ) {
            Schema::table('oz_ai_cabinet_analyzer_ai_analyses', function (Blueprint $table) {
                $table->dropColumn(['provider', 'credits_charged', 'billing_snapshot']);
            });
        }
    }
};
