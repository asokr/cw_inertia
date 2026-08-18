<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wb_ai_cabinet_analyzer_templates')
            && ! Schema::hasColumn('wb_ai_cabinet_analyzer_templates', 'credits_cost')
        ) {
            Schema::table('wb_ai_cabinet_analyzer_templates', function (Blueprint $table) {
                $table->unsignedInteger('credits_cost')->default(10)->after('data_sources');
            });
        }

        if (Schema::hasTable('oz_ai_cabinet_analyzer_templates')
            && ! Schema::hasColumn('oz_ai_cabinet_analyzer_templates', 'credits_cost')
        ) {
            Schema::table('oz_ai_cabinet_analyzer_templates', function (Blueprint $table) {
                $table->unsignedInteger('credits_cost')->default(10)->after('data_sources');
            });
        }

        if (Schema::hasTable('wb_ai_cabinet_analyzer_ai_analyses')
            && ! Schema::hasColumn('wb_ai_cabinet_analyzer_ai_analyses', 'credit_idempotency_key')
        ) {
            Schema::table('wb_ai_cabinet_analyzer_ai_analyses', function (Blueprint $table) {
                $table->string('credit_idempotency_key')->nullable()->after('error_message');
            });
        }

        if (Schema::hasTable('oz_ai_cabinet_analyzer_ai_analyses')
            && ! Schema::hasColumn('oz_ai_cabinet_analyzer_ai_analyses', 'credit_idempotency_key')
        ) {
            Schema::table('oz_ai_cabinet_analyzer_ai_analyses', function (Blueprint $table) {
                $table->string('credit_idempotency_key')->nullable()->after('error_message');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wb_ai_cabinet_analyzer_templates')
            && Schema::hasColumn('wb_ai_cabinet_analyzer_templates', 'credits_cost')
        ) {
            Schema::table('wb_ai_cabinet_analyzer_templates', function (Blueprint $table) {
                $table->dropColumn('credits_cost');
            });
        }

        if (Schema::hasTable('oz_ai_cabinet_analyzer_templates')
            && Schema::hasColumn('oz_ai_cabinet_analyzer_templates', 'credits_cost')
        ) {
            Schema::table('oz_ai_cabinet_analyzer_templates', function (Blueprint $table) {
                $table->dropColumn('credits_cost');
            });
        }

        if (Schema::hasTable('wb_ai_cabinet_analyzer_ai_analyses')
            && Schema::hasColumn('wb_ai_cabinet_analyzer_ai_analyses', 'credit_idempotency_key')
        ) {
            Schema::table('wb_ai_cabinet_analyzer_ai_analyses', function (Blueprint $table) {
                $table->dropColumn('credit_idempotency_key');
            });
        }

        if (Schema::hasTable('oz_ai_cabinet_analyzer_ai_analyses')
            && Schema::hasColumn('oz_ai_cabinet_analyzer_ai_analyses', 'credit_idempotency_key')
        ) {
            Schema::table('oz_ai_cabinet_analyzer_ai_analyses', function (Blueprint $table) {
                $table->dropColumn('credit_idempotency_key');
            });
        }
    }
};
