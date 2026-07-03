<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const OLD_CABINETS = 'wb_profit_analyzer_cabinets';
    private const OLD_REPORTS = 'wb_profit_reports';
    private const OLD_TEMPLATES = 'wb_profit_analysis_templates';
    private const OLD_AI_ANALYSES = 'wb_profit_report_ai_analyses';

    private const NEW_CABINETS = 'wb_ai_cabinet_analyzer_cabinets';
    private const NEW_REPORTS = 'wb_ai_cabinet_analyzer_reports';
    private const NEW_TEMPLATES = 'wb_ai_cabinet_analyzer_templates';
    private const NEW_AI_ANALYSES = 'wb_ai_cabinet_analyzer_ai_analyses';

    public function up(): void
    {
        if (!Schema::hasTable(self::OLD_CABINETS)) {
            return;
        }

        $this->dropForeignKeysOnAiAnalyses();
        $this->dropForeignKeysOnReports();

        $this->renameTable(self::OLD_CABINETS, self::NEW_CABINETS);
        $this->renameTable(self::OLD_TEMPLATES, self::NEW_TEMPLATES);
        $this->renameTable(self::OLD_REPORTS, self::NEW_REPORTS);
        $this->renameTable(self::OLD_AI_ANALYSES, self::NEW_AI_ANALYSES);

        $this->restoreForeignKeys();

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->where('guard_name', 'api')
                ->where('name', 'subscriber wb profit analyzer')
                ->update(['name' => 'subscriber wb ai cabinet analyzer']);
        }

        if (Schema::hasTable('ai_request_logs')) {
            DB::table('ai_request_logs')
                ->where('task_type', 'wb_profit_analyzer_ai')
                ->update(['task_type' => 'wb_ai_cabinet_analyzer_ai']);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::NEW_CABINETS)) {
            return;
        }

        $this->dropForeignKeysOnAiAnalyses(true);
        $this->dropForeignKeysOnReports(true);

        $this->renameTable(self::NEW_AI_ANALYSES, self::OLD_AI_ANALYSES);
        $this->renameTable(self::NEW_REPORTS, self::OLD_REPORTS);
        $this->renameTable(self::NEW_TEMPLATES, self::OLD_TEMPLATES);
        $this->renameTable(self::NEW_CABINETS, self::OLD_CABINETS);

        $this->restoreForeignKeys(false);

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->where('guard_name', 'api')
                ->where('name', 'subscriber wb ai cabinet analyzer')
                ->update(['name' => 'subscriber wb profit analyzer']);
        }

        if (Schema::hasTable('ai_request_logs')) {
            DB::table('ai_request_logs')
                ->where('task_type', 'wb_ai_cabinet_analyzer_ai')
                ->update(['task_type' => 'wb_profit_analyzer_ai']);
        }
    }

    private function renameTable(string $from, string $to): void
    {
        if (Schema::hasTable($from) && !Schema::hasTable($to)) {
            Schema::rename($from, $to);
        }
    }

    private function dropForeignKeysOnAiAnalyses(bool $useNewNames = false): void
    {
        $table = $useNewNames ? self::NEW_AI_ANALYSES : self::OLD_AI_ANALYSES;
        if (!Schema::hasTable($table)) {
            return;
        }

        $this->dropForeignByColumn($table, 'report_id');
        $this->dropForeignByColumn($table, 'template_id');
    }

    private function dropForeignKeysOnReports(bool $useNewNames = false): void
    {
        $table = $useNewNames ? self::NEW_REPORTS : self::OLD_REPORTS;
        if (!Schema::hasTable($table)) {
            return;
        }

        $this->dropForeignByColumn($table, 'cabinet_id');
    }

    private function restoreForeignKeys(bool $useNewNames = true): void
    {
        $cabinets = $useNewNames ? self::NEW_CABINETS : self::OLD_CABINETS;
        $reports = $useNewNames ? self::NEW_REPORTS : self::OLD_REPORTS;
        $templates = $useNewNames ? self::NEW_TEMPLATES : self::OLD_TEMPLATES;
        $aiAnalyses = $useNewNames ? self::NEW_AI_ANALYSES : self::OLD_AI_ANALYSES;

        if (Schema::hasTable($reports) && Schema::hasTable($cabinets)) {
            Schema::table($reports, function (Blueprint $table) use ($cabinets): void {
                $table->foreign('cabinet_id')
                    ->references('id')
                    ->on($cabinets)
                    ->onDelete('cascade');
            });
        }

        if (Schema::hasTable($aiAnalyses) && Schema::hasTable($reports)) {
            Schema::table($aiAnalyses, function (Blueprint $table) use ($reports): void {
                $table->foreign('report_id')
                    ->references('id')
                    ->on($reports)
                    ->onDelete('cascade');
            });
        }

        if (Schema::hasTable($aiAnalyses) && Schema::hasTable($templates)) {
            Schema::table($aiAnalyses, function (Blueprint $table) use ($templates): void {
                $table->foreign('template_id')
                    ->references('id')
                    ->on($templates)
                    ->onDelete('cascade');
            });
        }
    }

    private function dropForeignByColumn(string $table, string $column): void
    {
        if (!Schema::hasColumn($table, $column)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
            });
        } catch (\Throwable) {
            // FK may already be dropped.
        }
    }
};