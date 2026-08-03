<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cabinetId = (int) (DB::table('wb_ab_products')->value('cabinet_id') ?? 0);
echo "cabinet_id={$cabinetId}\n";

if ($cabinetId <= 0) {
    exit(1);
}

// Clear potential unique lock
$job = new App\Jobs\Wb\AbTesting\EnrichAbProductRatingsJob($cabinetId);
$uniqueId = method_exists($job, 'uniqueId') ? $job->uniqueId() : '';
echo "uniqueId={$uniqueId}\n";

// Try dispatch with afterResponse simulation vs immediate
App\Jobs\Wb\AbTesting\EnrichAbProductRatingsJob::dispatch($cabinetId);

echo 'jobs after dispatch=' . DB::table('jobs')->count() . PHP_EOL;
foreach (DB::table('jobs')->orderByDesc('id')->limit(3)->get() as $row) {
    $p = json_decode($row->payload, true);
    echo 'JOB ' . $row->id . ' ' . ($p['displayName'] ?? '?') . PHP_EOL;
}
