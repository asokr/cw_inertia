<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'wb_ai_cabinet_analyzer_templates';

    private const DEFAULT_SOURCES = '["ads","reviews","funnel"]';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'data_sources')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->json('data_sources')->nullable()->after('response_format');
            });
        }

        DB::table(self::TABLE)
            ->whereNull('data_sources')
            ->update(['data_sources' => self::DEFAULT_SOURCES]);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasColumn(self::TABLE, 'data_sources')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->dropColumn('data_sources');
            });
        }
    }
};
