<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'queue=' . config('queue.default') . PHP_EOL;
echo 'cache=' . config('cache.default') . PHP_EOL;
echo 'jobs=' . DB::table('jobs')->count() . PHP_EOL;
echo 'failed=' . DB::table('failed_jobs')->count() . PHP_EOL;

foreach (DB::table('jobs')->orderByDesc('id')->limit(10)->get() as $row) {
    $p = json_decode($row->payload, true);
    echo 'JOB ' . $row->id . ' q=' . $row->queue . ' name=' . ($p['displayName'] ?? '?') . ' attempts=' . $row->attempts . PHP_EOL;
}

foreach (DB::table('failed_jobs')->orderByDesc('id')->limit(5)->get() as $row) {
    $p = json_decode($row->payload, true);
    echo 'FAIL ' . $row->id . ' ' . ($p['displayName'] ?? '?') . PHP_EOL;
    echo substr((string) $row->exception, 0, 500) . PHP_EOL;
}

$stats = DB::table('wb_ab_products')
    ->selectRaw('count(*) as c, sum(case when price is not null then 1 else 0 end) as with_price, sum(case when rating is not null then 1 else 0 end) as with_rating')
    ->first();
print_r($stats);

// Try acquire unique lock key pattern
$cache = Illuminate\Support\Facades\Cache::getStore();
echo 'cache store=' . get_class($cache) . PHP_EOL;
