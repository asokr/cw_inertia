<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE `wb_profit_reports` MODIFY `result_json` LONGTEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `wb_profit_reports` MODIFY `result_json` JSON NULL');
    }
};
