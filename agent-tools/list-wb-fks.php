<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = DB::connection()->getDatabaseName();

$rows = DB::select(
    'SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = ?
       AND REFERENCED_TABLE_NAME IN (
         \'wb_repricer_cabinets\',\'wb_price_cabinets\',\'wb_profitability_cabinets\',
         \'wb_ai_cabinet_analyzer_cabinets\',\'subs_wb_feedbacks_clients\',\'wb_cabinets\'
       )
     ORDER BY REFERENCED_TABLE_NAME, TABLE_NAME',
    [$db]
);

foreach ($rows as $r) {
    echo "{$r->TABLE_NAME}.{$r->COLUMN_NAME} -> {$r->REFERENCED_TABLE_NAME} ({$r->CONSTRAINT_NAME})\n";
}

echo "count=" . count($rows) . "\n";
