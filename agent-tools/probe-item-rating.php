<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cabinet = App\Models\Subscribers\Wb\WbCabinet::query()->find(1);
if (! $cabinet) {
    echo "no cabinet\n";
    exit(1);
}

$apiKey = $cabinet->apikey;
$end = now('Europe/Moscow')->toDateString();
$start = now('Europe/Moscow')->subDays(30)->toDateString();

$bodies = [
    'period_empty_nm' => [
        'nmIds' => [],
        'currentPeriod' => ['start' => $start, 'end' => $end],
        'limit' => 50,
        'offset' => 0,
    ],
    'period_only' => [
        'currentPeriod' => ['start' => $start, 'end' => $end],
        'limit' => 50,
        'offset' => 0,
    ],
    'period_with_nms' => [
        'nmIds' => array_map('intval', DB::table('wb_ab_products')->where('cabinet_id', 1)->limit(5)->pluck('nm_id')->all()),
        'currentPeriod' => ['start' => $start, 'end' => $end],
        'limit' => 50,
        'offset' => 0,
    ],
    'period_compare' => [
        'currentPeriod' => ['start' => $start, 'end' => $end],
        'pastPeriod' => [
            'start' => now('Europe/Moscow')->subDays(60)->toDateString(),
            'end' => now('Europe/Moscow')->subDays(31)->toDateString(),
        ],
        'limit' => 50,
        'offset' => 0,
    ],
];

$url = 'https://seller-analytics-api.wildberries.ru/api/analytics/v2/item-rating';

foreach ($bodies as $name => $body) {
    echo "=== {$name} ===\n";
    echo json_encode($body, JSON_UNESCAPED_UNICODE) . "\n";

    $client = new GuzzleHttp\Client(['http_errors' => false, 'timeout' => 30]);
    try {
        $response = $client->post($url, [
            'headers' => [
                'Authorization' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => $body,
        ]);
        $code = $response->getStatusCode();
        $raw = $response->getBody()->getContents();
        echo "code={$code}\n";
        echo substr($raw, 0, 800) . "\n\n";
    } catch (Throwable $e) {
        echo 'ERR ' . $e->getMessage() . "\n\n";
    }

    sleep(22);
}
