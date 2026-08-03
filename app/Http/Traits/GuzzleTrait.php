<?php

namespace App\Http\Traits;

use App\Services\Wb\WbApiUsageService;
use GuzzleHttp;
use Illuminate\Support\Facades\Log;

trait GuzzleTrait
{
    /** Total request timeout (seconds). Overrides PHP default_socket_timeout (~60). */
    private const GUZZLE_TIMEOUT_SECONDS = 120;

    private const GUZZLE_CONNECT_TIMEOUT_SECONDS = 15;

    private function putRequest($url, $apiKey = '', $data = array())
    {
        $headers = [
            'accept' => 'application/json',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Content-Type' => 'application/json; charset=utf-8',
            'Authorization' => $apiKey
        ];

        $client = $this->makeGuzzleClient($headers);

        try {
            $response = $client->put($url, ['json' => $data]);
            $result = [
                'headers' => $response->getHeaders(),
                'response' => $response->getBody()->getContents(),
                'code' => $response->getStatusCode(),
            ];
        } catch (\Throwable $exception) {
            $result = $this->guzzleExceptionResult('PUT', $url, $exception);
        }

        $this->trackWbApiUsage($apiKey, 'PUT', $url, $data, $result['code'], $result['response'] ?? null);

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

        $client = $this->makeGuzzleClient($headers);

        try {
            $response = $client->patch($url, ['json' => $data]);
            $result = [
                'headers' => $response->getHeaders(),
                'response' => $response->getBody()->getContents(),
                'code' => $response->getStatusCode(),
            ];
        } catch (\Throwable $exception) {
            $result = $this->guzzleExceptionResult('PATCH', $url, $exception);
        }

        $this->trackWbApiUsage($apiKey, 'PATCH', $url, $data, $result['code'], $result['response'] ?? null);

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

        $client = $this->makeGuzzleClient($headers);

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
            $result = $this->guzzleExceptionResult('GET', $url, $exception, $function);
        }

        if ($apiKey != '') {
            $this->trackWbApiUsage($apiKey, 'GET', $url, $data, $result['code'], $result['response'] ?? null);
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

        $client = $this->makeGuzzleClient($headers);

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
            $result = $this->guzzleExceptionResult('POST', $url, $exception, $function);
        }

        $this->trackWbApiUsage($apiKey, 'POST', $url, $data, $result['code'], $result['response'] ?? null);

        return $result;
    }

    /**
     * Binary/multipart POST (e.g. Content API media/file).
     *
     * @param  array<string, string>  $extraHeaders
     * @param  array<int, array<string, mixed>>  $multipart
     * @return array{headers: array, response: string, code: int}
     */
    private function postMultipartRequest(
        string $url,
        string $apiKey,
        array $multipart,
        array $extraHeaders = [],
        string $function = '',
    ): array {
        $headers = array_merge([
            'accept' => 'application/json',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Authorization' => $apiKey,
        ], $extraHeaders);

        // Guzzle sets Content-Type with boundary for multipart.
        unset($headers['Content-Type'], $headers['content-type']);

        $client = $this->makeGuzzleClient($headers);

        try {
            $response = $client->post($url, ['multipart' => $multipart]);
            $result = [
                'headers' => $response->getHeaders(),
                'response' => $response->getBody()->getContents(),
                'code' => $response->getStatusCode(),
            ];
        } catch (\Throwable $exception) {
            $result = $this->guzzleExceptionResult('POST', $url, $exception, $function);
        }

        $this->trackWbApiUsage($apiKey, 'POST', $url, ['multipart' => true], $result['code'], $result['response'] ?? null);

        return $result;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function makeGuzzleClient(array $headers): GuzzleHttp\Client
    {
        return new GuzzleHttp\Client([
            'headers' => $headers,
            'http_errors' => false,
            'connect_timeout' => self::GUZZLE_CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::GUZZLE_TIMEOUT_SECONDS,
        ]);
    }

    /**
     * @return array{headers: array, response: string, code: int}
     */
    private function guzzleExceptionResult(string $method, string $url, \Throwable $exception, string $function = ''): array
    {
        $message = $exception->getMessage();
        $isTimeout = $this->isGuzzleTimeoutMessage($message);

        try {
            Log::channel('wb_api_response')->warning('WB HTTP exception', [
                'method' => $method,
                'url' => $url,
                'function' => $function !== '' ? $function : null,
                'timeout' => $isTimeout,
                'message' => $message,
            ]);
        } catch (\Throwable) {
            Log::warning('WB HTTP exception: '.$message, [
                'method' => $method,
                'url' => $url,
            ]);
        }

        return [
            'headers' => [],
            // Keep raw for diagnostics; parsers humanize timeout/network cases.
            'response' => $message,
            // 504 = gateway/upstream timeout style; 0 was ambiguous for clients.
            'code' => $isTimeout ? 504 : 0,
        ];
    }

    private function isGuzzleTimeoutMessage(string $message): bool
    {
        $normalized = mb_strtolower($message);

        return str_contains($normalized, 'timeout')
            || str_contains($normalized, 'timed out')
            || str_contains($normalized, 'curl error 28')
            || str_contains($normalized, 'operation timed out');
    }

    private function trackWbApiUsage(
        ?string $apiKey,
        string $method,
        string $url,
        ?array $requestData = null,
        ?int $responseCode = null,
        mixed $responseBody = null,
    ): void {
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
            app(WbApiUsageService::class)->recordRequest(
                $apiKey,
                $method,
                $url,
                $requestData,
                $responseCode,
                $responseBody,
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
