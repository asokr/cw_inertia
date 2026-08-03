<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$s = DB::table('wb_ab_products')
    ->selectRaw('count(*) c, sum(case when price is not null then 1 else 0 end) p, sum(case when rating is not null then 1 else 0 end) r, avg(rating) a')
    ->first();
print_r($s);

foreach (DB::table('wb_ab_products')->whereNotNull('rating')->orderByDesc('rating')->limit(8)->get(['nm_id', 'rating', 'rating_updated_at']) as $row) {
    echo $row->nm_id . ' rating=' . $row->rating . ' at=' . $row->rating_updated_at . PHP_EOL;
}
