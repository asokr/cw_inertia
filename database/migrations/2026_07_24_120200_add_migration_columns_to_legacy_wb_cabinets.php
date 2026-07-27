<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy per-tool cabinet tables that need migration tracking.
     *
     * @var list<string>
     */
    private array $tables = [
        'subs_wb_feedbacks_clients',
        'wb_price_cabinets',
        'wb_repricer_cabinets',
        'wb_profitability_cabinets',
        'wb_ai_cabinet_analyzer_cabinets',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'is_migrated')) {
                    $table->boolean('is_migrated')->default(false)->after('id');
                }
                if (! Schema::hasColumn($tableName, 'migrated_at')) {
                    $table->timestamp('migrated_at')->nullable()->after('is_migrated');
                }
                if (! Schema::hasColumn($tableName, 'wb_cabinet_id')) {
                    $table->unsignedBigInteger('wb_cabinet_id')->nullable()->index()->after('migrated_at');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'wb_cabinet_id')) {
                    $table->dropColumn('wb_cabinet_id');
                }
                if (Schema::hasColumn($tableName, 'migrated_at')) {
                    $table->dropColumn('migrated_at');
                }
                if (Schema::hasColumn($tableName, 'is_migrated')) {
                    $table->dropColumn('is_migrated');
                }
            });
        }
    }
};
