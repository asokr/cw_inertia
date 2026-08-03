<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cabinet = App\Models\Subscribers\Wb\WbCabinet::query()->find(1);
$apiKey = $cabinet->apikey;
$end = now('Europe/Moscow')->toDateString();
$start = now('Europe/Moscow')->subDays(30)->toDateString();
$nmIds = array_map('intval', DB::table('wb_ab_products')->where('cabinet_id', 1)->limit(5)->pluck('nm_id')->all());

$bodies = [
    'orderBy_feedbackRating_desc' => [
        'nmIds' => $nmIds,
        'currentPeriod' => ['start' => $start, 'end' => $end],
        'orderBy' => ['field' => 'feedbackRating', 'mode' => 'desc'],
        'limit' => 50,
        'offset' => 0,
    ],
    'orderBy_field_only' => [
        'currentPeriod' => ['start' => $start, 'end' => $end],
        'orderBy' => ['field' => 'feedbackRating', 'mode' => 'desc'],
        'limit' => 50,
        'offset' => 0,
    ],
    'orderBy_nmId' => [
        'currentPeriod' => ['start' => $start, 'end' => $end],
        'orderBy' => ['field' => 'nmId', 'mode' => 'asc'],
        'limit' => 50,
        'offset' => 0,
    ],
    'orderBy_productRating' => [
        'currentPeriod' => ['start' => $start, 'end' => $end],
        'orderBy' => ['field' => 'productRating', 'mode' => 'desc'],
        'limit' => 50,
        'offset' => 0,
    ],
];

$url = 'https://seller-analytics-api.wildberries.ru/api/analytics/v2/item-rating';
$client = new GuzzleHttp\Client(['http_errors' => false, 'timeout' => 30]);

foreach ($bodies as $name => $body) {
    echo "=== {$name} ===\n";
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
    echo substr($raw, 0, 1200) . "\n\n";
    sleep(22);
}
