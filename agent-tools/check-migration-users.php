<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Support\Wb\WbCabinetServiceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$tables = [
    'wb_price_cabinets',
    'wb_repricer_cabinets',
    'wb_profitability_cabinets',
    'wb_ai_cabinet_analyzer_cabinets',
    'subs_wb_feedbacks_clients',
];

foreach ($tables as $t) {
    if (! Schema::hasTable($t)) {
        echo "{$t}: missing\n";
        continue;
    }
    $total = DB::table($t)->count();
    $unmig = Schema::hasColumn($t, 'is_migrated')
        ? DB::table($t)->where(function ($q) {
            $q->where('is_migrated', 0)->orWhereNull('is_migrated');
        })->count()
        : $total;
    echo "{$t}: total={$total} unmigrated={$unmig} has_col=" . (Schema::hasColumn($t, 'is_migrated') ? 'yes' : 'no') . "\n";
}

echo "wb_cabinets=" . (Schema::hasTable('wb_cabinets') ? DB::table('wb_cabinets')->count() : 'missing') . "\n";

$registry = app(WbCabinetServiceRegistry::class);
$found = 0;
User::query()->orderBy('id')->chunkById(100, function ($users) use ($registry, &$found) {
    foreach ($users as $user) {
        if ($registry->userNeedsMigration($user)) {
            $found++;
            echo "needs migration: user_id={$user->id} email={$user->email}\n";
            if ($found >= 8) {
                return false;
            }
        }
    }
});

echo "sample_found={$found}\n";
