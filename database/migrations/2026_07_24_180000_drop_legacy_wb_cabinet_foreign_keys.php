<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Child tables still FK-reference per-tool cabinet tables.
 * Unified migration rewrites cabinet_id / client_id to wb_cabinets.id,
 * so those legacy foreign keys must be removed first.
 *
 * Do not re-add FKs to wb_cabinets until every user finishes data migration
 * (mixed old/new ids would violate constraints).
 */
return new class extends Migration
{
    /**
     * table => [column => referenced_table]
     *
     * @var array<string, array<string, string>>
     */
    private array $constraints = [
        'wb_repricer_stocks' => ['cabinet_id' => 'wb_repricer_cabinets'],
        'wb_repricer_settings' => ['cabinet_id' => 'wb_repricer_cabinets'],
        'wb_repricer_competitors' => ['cabinet_id' => 'wb_repricer_cabinets'],
        'wb_repricer_logs' => ['cabinet_id' => 'wb_repricer_cabinets'],

        'wb_price_calc_v2_data' => ['cabinet_id' => 'wb_price_cabinets'],
        'wb_price_calc_v2_settings' => ['cabinet_id' => 'wb_price_cabinets'],
        'wb_price_data' => ['cabinet_id' => 'wb_price_cabinets'],
        'wp_price_special_data' => ['cabinet_id' => 'wb_price_cabinets'],
        'wb_price_calc_v3_data' => ['cabinet_id' => 'wb_price_cabinets'],

        'wb_profitability_reports' => ['cabinet_id' => 'wb_profitability_cabinets'],

        'wb_ai_cabinet_analyzer_reports' => ['cabinet_id' => 'wb_ai_cabinet_analyzer_cabinets'],

        'subs_wb_feedbacks_templates' => ['client_id' => 'subs_wb_feedbacks_clients'],
        'wb_feedbacks_reviews' => ['cabinet_id' => 'subs_wb_feedbacks_clients'],
        'wb_feedbacks_review_statistics' => ['cabinet_id' => 'subs_wb_feedbacks_clients'],
        'wb_feedbacks_review_product_statistics' => ['cabinet_id' => 'subs_wb_feedbacks_clients'],
        'wb_feedbacks_review_category_statistics' => ['cabinet_id' => 'subs_wb_feedbacks_clients'],
    ];

    public function up(): void
    {
        foreach ($this->constraints as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $referencedTable) {
                $this->dropForeignKeysOnColumn($table, $column);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->constraints as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $referencedTable) {
                if (! Schema::hasTable($referencedTable) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }

                if ($this->hasForeignKeyOnColumn($table, $column)) {
                    continue;
                }

                try {
                    Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable) {
                        $blueprint->foreign($column)
                            ->references('id')
                            ->on($referencedTable)
                            ->cascadeOnDelete();
                    });
                } catch (\Throwable) {
                    // Best-effort restore; mixed data may block re-adding FKs.
                }
            }
        }
    }

    private function dropForeignKeysOnColumn(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        $names = $this->foreignKeyNames($table, $column);
        if ($names === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($names) {
            foreach ($names as $name) {
                try {
                    $blueprint->dropForeign($name);
                } catch (\Throwable) {
                    try {
                        $blueprint->dropForeign([$name]);
                    } catch (\Throwable) {
                        // Ignore if already gone.
                    }
                }
            }
        });

        // MySQL sometimes needs raw DROP if Blueprint naming differs.
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

        return array_values(array_unique(array_map(
            static fn ($row) => (string) $row->CONSTRAINT_NAME,
            $rows
        )));
    }
};
