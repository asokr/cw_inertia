<?php
require __DIR__ . "/../vendor/autoload.php";
$app = require __DIR__ . "/../bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach ([1, 2, 3] as $id) {
    $p = App\Models\Subscribers\SubscribersPlans::find($id);
    echo "=== $id {$p->name} ===\n";
    echo "limits_plan: " . json_encode($p->limits_plan, JSON_UNESCAPED_UNICODE) . "\n";
    echo "limits_month: " . json_encode($p->limits_month, JSON_UNESCAPED_UNICODE) . "\n";
    echo "permissions: " . json_encode($p->permissions, JSON_UNESCAPED_UNICODE) . "\n\n";
}

echo "active test: " . App\Models\Subscribers\SubscribersSubscriptions::where("plan_id", 2)->where("status", 1)->count() . "\n";
echo "all test: " . App\Models\Subscribers\SubscribersSubscriptions::where("plan_id", 2)->count() . "\n";
