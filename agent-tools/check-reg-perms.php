<?php
require __DIR__ . "/../vendor/autoload.php";
$app = require __DIR__ . "/../bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$plan = App\Models\Subscribers\SubscribersPlans::find(2);
echo "PLAN2 permissions type=" . gettype($plan->permissions) . " value=" . json_encode($plan->permissions, JSON_UNESCAPED_UNICODE) . PHP_EOL;

// recent active test subscriptions
$subs = App\Models\Subscribers\SubscribersSubscriptions::query()
    ->where("plan_id", 2)
    ->where("status", 1)
    ->orderByDesc("id")
    ->limit(5)
    ->get();

foreach ($subs as $sub) {
    $subscriber = App\Models\Subscribers\Subscribers::find($sub->subscribers_id);
    $user = $subscriber?->getUser();
    if (!$user) {
        echo "sub#{$sub->id} no user\n";
        continue;
    }
    $perms = $user->getPermissionNames()->values()->all();
    $roles = $user->getRoleNames()->values()->all();
    echo "user#{$user->id} {$user->email} roles=" . json_encode($roles, JSON_UNESCAPED_UNICODE)
        . " perms=" . json_encode($perms, JSON_UNESCAPED_UNICODE)
        . " end={$sub->end_date} created={$sub->created_at}\n";
}

// simulate assignment with current plan
echo "\n--- simulate givePermissionTo ---\n";
try {
    $testUser = App\Models\User::factory()->make(["email" => "sim@test.local", "name" => "Sim"]);
    // just validate permissions exist
    foreach (($plan->permissions ?? []) as $p) {
        $exists = Spatie\Permission\Models\Permission::where("name", $p)->where("guard_name", "web")->exists();
        echo "perm '$p' exists=" . ($exists ? "yes" : "NO") . "\n";
    }
} catch (Throwable $e) {
    echo $e->getMessage() . "\n";
}
