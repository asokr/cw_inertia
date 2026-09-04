<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * После унификации кабинетов Ozon SyncPriceCalcJob пишет cabinet_id = oz_cabinets.id,
 * а FK FBO/FBS всё ещё ссылается на пустую oz_price_calc_cabinets → MySQL 1452.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $childTables = [
        'oz_price_calc_fbo',
        'oz_price_calc_fbs',
    ];

    public function up(): void
    {
        foreach ($this->childTables as $table) {
            $this->repointCabinetForeign($table);
        }

        Schema::dropIfExists('oz_price_calc_cabinets');
    }

    public function down(): void
    {
        if (! Schema::hasTable('oz_price_calc_cabinets')) {
            Schema::create('oz_price_calc_cabinets', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name')->nullable();
                $table->string('client_id')->nullable();
                $table->text('apikey')->nullable();
                $table->text('last_sync_error')->nullable();
                $table->timestamps();
            });
        }

        foreach ($this->childTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'cabinet_id')) {
                continue;
            }

            $this->dropForeignKeysOnColumn($table, 'cabinet_id');

            try {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->foreign('cabinet_id')
                        ->references('id')
                        ->on('oz_price_calc_cabinets')
                        ->cascadeOnDelete();
                });
            } catch (\Throwable) {
                // Откат best-effort: смешанные id могут мешать вернуть старый FK.
            }
        }
    }

    private function repointCabinetForeign(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'cabinet_id')) {
            return;
        }

        $this->deleteOrphans($table);
        $this->dropForeignKeysOnColumn($table, 'cabinet_id');

        if (! Schema::hasTable('oz_cabinets') || $this->hasForeignKeyOnColumn($table, 'cabinet_id')) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->foreign('cabinet_id', $table.'_cabinet_id_oz_cabinets_fk')
                    ->references('id')
                    ->on('oz_cabinets')
                    ->cascadeOnDelete();
            });
        } catch (\Throwable) {
            // Снять старый FK достаточно, чтобы синхронизация снова писала строки.
        }
    }

    private function deleteOrphans(string $table): void
    {
        if (! Schema::hasTable('oz_cabinets')) {
            return;
        }

        try {
            $validIds = DB::table('oz_cabinets')->pluck('id');

            if ($validIds->isEmpty()) {
                DB::table($table)->delete();

                return;
            }

            DB::table($table)->whereNotIn('cabinet_id', $validIds)->delete();
        } catch (\Throwable) {
        }
    }

    private function dropForeignKeysOnColumn(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        $names = $this->foreignKeyNames($table, $column);

        if ($names !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($names) {
                foreach ($names as $name) {
                    try {
                        $blueprint->dropForeign($name);
                    } catch (\Throwable) {
                        try {
                            $blueprint->dropForeign([$name]);
                        } catch (\Throwable) {
                        }
                    }
                }
            });
        }

        foreach ($this->foreignKeyNames($table, $column) as $name) {
            try {
                DB::statement(sprintf(
                    'ALTER TABLE `%s` DROP FOREIGN KEY `%s`',
                    str_replace('`', '``', $table),
                    str_replace('`', '``', $name)
                ));
            } catch (\Throwable) {
            }
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable) {
        }
    }

    private function hasForeignKeyOnColumn(string $table, string $column): bool
    {
        return $this->foreignKeyNames($table, $column) !== [];
    }

    /**
     * @return list<string>
     */
    private function foreignKeyNames(string $table, string $column): array
    {
        try {
            $database = DB::connection()->getDatabaseName();

            $rows = DB::select(
                'SELECT CONSTRAINT_NAME
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                   AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$database, $table, $column]
            );
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn ($row) => (string) $row->CONSTRAINT_NAME,
            $rows
        )));
    }
};
