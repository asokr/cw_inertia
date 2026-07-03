<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('wb_profit_analyzer_cabinets')) {
            Schema::create('wb_profit_analyzer_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->text('apikey');
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('wb_profit_reports')) {
            return;
        }

        // Отчеты старой схемы на profitability невалидны для новой модели доступа.
        DB::table('wb_profit_reports')->delete();

        if (!Schema::hasColumn('wb_profit_reports', 'cabinet_id')) {
            Schema::table('wb_profit_reports', function (Blueprint $table) {
                $table->unsignedBigInteger('cabinet_id')->nullable()->after('id');
            });
        }

        if (Schema::hasColumn('wb_profit_reports', 'profitability_cabinet_id')) {
            $this->dropForeignByColumn('wb_profit_reports', 'profitability_cabinet_id');
            $this->dropIndexesByColumn('wb_profit_reports', 'profitability_cabinet_id');

            Schema::table('wb_profit_reports', function (Blueprint $table) {
                $table->dropColumn('profitability_cabinet_id');
            });
        }

        DB::statement('ALTER TABLE `wb_profit_reports` MODIFY `cabinet_id` BIGINT UNSIGNED NOT NULL');

        if (!$this->hasIndex('wb_profit_reports', 'wb_profit_reports_cabinet_id_index')) {
            Schema::table('wb_profit_reports', function (Blueprint $table) {
                $table->index('cabinet_id');
            });
        }

        if (!$this->hasForeign('wb_profit_reports', 'wb_profit_reports_cabinet_id_foreign')) {
            Schema::table('wb_profit_reports', function (Blueprint $table) {
                $table->foreign('cabinet_id')
                    ->references('id')
                    ->on('wb_profit_analyzer_cabinets')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('wb_profit_reports')) {
            return;
        }

        DB::table('wb_profit_reports')->delete();

        if (!Schema::hasColumn('wb_profit_reports', 'profitability_cabinet_id')) {
            Schema::table('wb_profit_reports', function (Blueprint $table) {
                $table->unsignedBigInteger('profitability_cabinet_id')->nullable()->after('id');
            });
        }

        if (Schema::hasColumn('wb_profit_reports', 'cabinet_id')) {
            $this->dropForeignByColumn('wb_profit_reports', 'cabinet_id');
            $this->dropIndexesByColumn('wb_profit_reports', 'cabinet_id');

            Schema::table('wb_profit_reports', function (Blueprint $table) {
                $table->dropColumn('cabinet_id');
            });
        }

        DB::statement('ALTER TABLE `wb_profit_reports` MODIFY `profitability_cabinet_id` BIGINT UNSIGNED NOT NULL');

        if (!$this->hasIndex('wb_profit_reports', 'wb_profit_reports_profitability_cabinet_id_index')) {
            Schema::table('wb_profit_reports', function (Blueprint $table) {
                $table->index('profitability_cabinet_id');
            });
        }

        if (!$this->hasForeign('wb_profit_reports', 'wb_profit_reports_profitability_cabinet_id_foreign')) {
            Schema::table('wb_profit_reports', function (Blueprint $table) {
                $table->foreign('profitability_cabinet_id')
                    ->references('id')
                    ->on('wb_profitability_cabinets')
                    ->onDelete('cascade');
            });
        }
    }

    private function dropForeignByColumn(string $tableName, string $column): void
    {
        $constraints = DB::select(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$tableName, $column]
        );

        foreach ($constraints as $constraint) {
            $name = (string) ($constraint->CONSTRAINT_NAME ?? '');
            if ($name === '') {
                continue;
            }

            DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $tableName, $name));
        }
    }

    private function dropIndexesByColumn(string $tableName, string $column): void
    {
        $indexes = DB::select(
            'SELECT DISTINCT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND INDEX_NAME <> "PRIMARY"',
            [$tableName, $column]
        );

        foreach ($indexes as $index) {
            $name = (string) ($index->INDEX_NAME ?? '');
            if ($name === '') {
                continue;
            }

            DB::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $tableName, $name));
        }
    }

    private function hasForeign(string $tableName, string $foreignName): bool
    {
        $result = DB::selectOne(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_TYPE = "FOREIGN KEY"
               AND CONSTRAINT_NAME = ?
             LIMIT 1',
            [$tableName, $foreignName]
        );

        return $result !== null;
    }

    private function hasIndex(string $tableName, string $indexName): bool
    {
        $result = DB::selectOne(
            'SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1',
            [$tableName, $indexName]
        );

        return $result !== null;
    }
};
