<?php

namespace App\Services\Wb;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WbSearchService
{
    private string $baseUrl;
    private ?string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.wb_search.url'), '/');
        $this->token = config('services.wb_search.token');
    }

    public function dispatchSearch(int $requestId, string $query): bool
    {
        return $this->post('/search', [
            'requestId' => $requestId,
            'query' => $query,
        ]);
    }

    public function recommendations(int $nmId): ?array
    {
        return $this->postForData('/recommendations', ['nmId' => $nmId]);
    }

    public function product(int $nmId): ?array
    {
        return $this->postForData('/product', [
            'nmId' => $nmId,
        ]);
    }

    public function health(): ?array
    {
        return $this->get('/health');
    }

    private function get(string $path, array $query = []): ?array
    {
        try {
            $response = $this->http(10)->get($this->baseUrl . $path, $query);

            if ($response->successful()) {
                $decoded = $response->json();
                return is_array($decoded) ? $decoded : null;
            }

            Log::warning('WB search service responded with error', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('WB search service request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function post(string $path, array $payload): bool
    {
        try {
            $response = $this->http(15)->post($this->baseUrl . $path, $payload);

            if ($response->successful()) {
                return true;
            }

            Log::warning('WB search service responded with error', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('WB search service request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function postForData(string $path, array $payload): ?array
    {
        try {
            $response = $this->http(15)->post($this->baseUrl . $path, $payload);

            if ($response->successful()) {
                $decoded = $response->json();
                return is_array($decoded) ? $decoded : null;
            }

            Log::warning('WB search service responded with error', [
                'path' => $path,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('WB search service request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function http(int $timeout = 15)
    {
        $request = Http::acceptJson()->timeout($timeout);

        if ($this->token) {
            $request->withToken($this->token);
        }

        return $request;
    }
}
