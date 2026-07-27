<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cols = Illuminate\Support\Facades\Schema::getColumnListing('extra_limits');
echo 'columns=' . implode(',', $cols) . PHP_EOL;

// Simulate full update request like the browser
$user = App\Models\User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', ['super-admin', 'Супер-Админ']))->first();
if (! $user) {
    echo "no admin user\n";
    exit(1);
}

$limit = App\Models\ExtraLimits::query()->first();
echo "admin={$user->id} limit={$limit->id}\n";

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Create a put request
$request = Illuminate\Http\Request::create(
    "/cw-page/extra-limits/{$limit->id}",
    'POST',
    [
        '_method' => 'PUT',
        'slug' => $limit->slug,
        'name' => $limit->name . ' (test)',
        'price' => 1.23,
        'order' => $limit->order,
    ]
);
$request->headers->set('Accept', 'text/html, application/xhtml+xml');
$request->setLaravelSession($app['session.store']);
$app['auth']->guard('web')->login($user);

// CSRF - disable for this script by setting session token
$token = 'test-token';
$app['session.store']->start();
$app['session.store']->put('_token', $token);
$request->cookies->set(config('session.cookie'), $app['session.store']->getId());
$request->headers->set('X-CSRF-TOKEN', $token);
$request->request->set('_token', $token);

try {
    $response = $kernel->handle($request);
    echo 'status=' . $response->getStatusCode() . PHP_EOL;
    echo 'location=' . $response->headers->get('Location') . PHP_EOL;
    if ($response->getStatusCode() >= 400) {
        echo substr($response->getContent(), 0, 500) . PHP_EOL;
    }
    $kernel->terminate($request, $response);
} catch (Throwable $e) {
    echo 'exception=' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
}

$limit->refresh();
echo 'name_after=' . $limit->name . PHP_EOL;
