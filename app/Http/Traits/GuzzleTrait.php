<?php

namespace App\Http\Traits;

use App\Services\Wb\WbApiUsageService;
use GuzzleHttp;

trait GuzzleTrait
{
    private function putRequest($url, $apiKey = '', $data = array())
    {
        $headers = [
            'accept' => 'application/json',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Content-Type' => 'application/json; charset=utf-8',
            'Authorization' => $apiKey
        ];

        $client = new GuzzleHttp\Client([
            'headers' => $headers,
            'http_errors' => false
        ]);

        try {
            $response = $client->put($url, ['json' => $data]);
            $result = [
                'headers' => $response->getHeaders(),
                'response' => $response->getBody()->getContents(),
                'code' => $response->getStatusCode(),
            ];
        } catch (\Throwable $exception) {
            $result = [
                'headers' => [],
                'response' => $exception->getMessage(),
                'code' => 0,
            ];
        }

        $this->trackWbApiUsage($apiKey, 'PUT', $url, $data, $result['code']);

        return $result;
    }

    private function patchRequest($url, $apiKey = '', $data = array())
    {
        $headers = [
            'accept' => 'application/json',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Content-Type' => 'application/json; charset=utf-8',
            'Authorization' => $apiKey
        ];

        $client = new GuzzleHttp\Client([
            'headers' => $headers,
            'http_errors' => false
        ]);

        try {
            $response = $client->patch($url, ['json' => $data]);
            $result = [
                'headers' => $response->getHeaders(),
                'response' => $response->getBody()->getContents(),
                'code' => $response->getStatusCode(),
            ];
        } catch (\Throwable $exception) {
            $result = [
                'headers' => [],
                'response' => $exception->getMessage(),
                'code' => 0,
            ];
        }

        $this->trackWbApiUsage($apiKey, 'PATCH', $url, $data, $result['code']);

        return $result;
    }

    private function getRequest($url, $apiKey = '', $data = array(), $function = '')
    {
        $headers = [
            'accept' => 'application/json',
            'Accept-Encoding' => 'gzip, deflate, br'
        ];

        if ($apiKey != '') {
            $headers['Authorization'] = $apiKey;
        }

        $client = new GuzzleHttp\Client([
            'headers' => $headers,
            'http_errors' => false
        ]);

        try {
            $response = $client->get($url, [
                'query' => $data
            ]);
            $result = [
                'headers' => $response->getHeaders(),
                'response' => $response->getBody()->getContents(),
                'code' => $response->getStatusCode(),
            ];
        } catch (\Throwable $exception) {
            $result = [
                'headers' => [],
                'response' => $exception->getMessage(),
                'code' => 0,
            ];
        }

        if ($apiKey != '') {
            $this->trackWbApiUsage($apiKey, 'GET', $url, $data, $result['code']);
        }

        return $result;
    }

    private function postRequest($url, $apiKey = '', $data = array(), $function = '')
    {
        $headers = [
            'accept' => 'application/json',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Content-Type' => 'application/json; charset=utf-8',
            'Authorization' => $apiKey
        ];

        $client = new GuzzleHttp\Client([
            'headers' => $headers,
            'http_errors' => false
        ]);

        $params = array(
            'json' => $data
        );

        try {
            $response = $client->post($url, $params);
            $result = [
                'headers' => $response->getHeaders(),
                'response' => $response->getBody()->getContents(),
                'code' => $response->getStatusCode(),
            ];
        } catch (\Throwable $exception) {
            $result = [
                'headers' => [],
                'response' => $exception->getMessage(),
                'code' => 0,
            ];
        }

        $this->trackWbApiUsage($apiKey, 'POST', $url, $data, $result['code']);

        return $result;
    }

    private function trackWbApiUsage(?string $apiKey, string $method, string $url, ?array $requestData = null, ?int $responseCode = null): void
    {
        if (! class_exists(WbApiUsageService::class)) {
            return;
        }

        $apiKey = $apiKey !== null ? trim($apiKey) : '';

        if ($apiKey === '') {
            return;
        }

        if (WbApiUsageService::isTrackingDisabled()) {
            return;
        }

        try {
            app(WbApiUsageService::class)->recordRequest($apiKey, $method, $url, $requestData, $responseCode);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
