<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cabinet = App\Models\Subscribers\Wb\WbCabinet::query()->find(1);
$apiKey = $cabinet->apikey;
$end = now('Europe/Moscow')->subDay()->toDateString();
$start = now('Europe/Moscow')->subDays(30)->toDateString();

$body = [
    'currentPeriod' => ['start' => $start, 'end' => $end],
    'orderBy' => ['field' => 'feedbackRating', 'mode' => 'desc'],
    'limit' => 50,
    'offset' => 0,
];

$url = 'https://seller-analytics-api.wildberries.ru/api/analytics/v2/item-rating';
$client = new GuzzleHttp\Client(['http_errors' => false, 'timeout' => 60]);

echo json_encode($body, JSON_UNESCAPED_UNICODE) . "\n";
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
echo substr($raw, 0, 2500) . "\n";
