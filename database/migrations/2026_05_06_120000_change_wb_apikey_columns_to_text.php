<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Таблицы, где хранится WB API ключ.
     */
    private array $tables = [
        'wb_price_cabinets',
        'wb_repricer_cabinets',
        'wb_profitability_cabinets',
        'wb_profit_analyzer_cabinets',
        'subs_wb_feedbacks_clients',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            $this->alterApikeyToText($table);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            $this->alterApikeyToVarchar1500IfSafe($table);
        }
    }

    private function alterApikeyToText(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'apikey')) {
            return;
        }

        $nullableSql = $this->isColumnNullable($table, 'apikey') ? 'NULL' : 'NOT NULL';
        DB::statement(sprintf('ALTER TABLE `%s` MODIFY `apikey` TEXT %s', $table, $nullableSql));
    }

    private function alterApikeyToVarchar1500IfSafe(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'apikey')) {
            return;
        }

        $maxLength = (int) DB::table($table)->selectRaw('MAX(CHAR_LENGTH(apikey)) as max_len')->value('max_len');
        if ($maxLength > 1500) {
            return;
        }

        $nullableSql = $this->isColumnNullable($table, 'apikey') ? 'NULL' : 'NOT NULL';
        DB::statement(sprintf('ALTER TABLE `%s` MODIFY `apikey` VARCHAR(1500) %s', $table, $nullableSql));
    }

    private function isColumnNullable(string $table, string $column): bool
    {
        $connection = DB::connection();
        $row = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$connection->getDatabaseName(), $table, $column]
        );

        return strtoupper((string) ($row->IS_NULLABLE ?? 'NO')) === 'YES';
    }
};
