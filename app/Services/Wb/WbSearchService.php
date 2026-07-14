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

    public function product(int $nmId): ?array
    {
        return $this->postForData('/product', [
            'nmId' => $nmId,
        ]);
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
