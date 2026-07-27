<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$m = new App\Models\ExtraLimits();
echo 'table=' . $m->getTable() . PHP_EOL;
echo 'count=' . App\Models\ExtraLimits::count() . PHP_EOL;
$first = App\Models\ExtraLimits::query()->first();
echo 'first=' . json_encode($first?->toArray(), JSON_UNESCAPED_UNICODE) . PHP_EOL;
$bound = (new App\Models\ExtraLimits())->resolveRouteBinding($first?->id);
echo 'binding=' . ($bound ? 'ok id=' . $bound->id : 'fail') . PHP_EOL;

// Simulate route match
$router = app('router');
$route = collect($router->getRoutes())->first(function ($r) {
    return $r->uri() === 'cw-page/extra-limits/{extraLimit}' && in_array('PUT', $r->methods());
});
echo 'route=' . ($route ? $route->uri() . ' action=' . $route->getActionName() : 'not found') . PHP_EOL;
