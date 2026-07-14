<?php

namespace App\Http\Controllers\Web\Subscriber\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

trait HandlesApiResponses
{
    /**
     * Build an internal API request with params available for both GET and POST handlers.
     *
     * Uses merge() so params are visible to JSON Inertia requests (not only form/query bags).
     *
     * @param  array<string, mixed>  $params
     */
    protected function apiRequestWith(Request $request, array $params): Request
    {
        $apiRequest = $request->duplicate();

        $mergeParams = [];
        foreach ($params as $key => $value) {
            if ($value instanceof \Illuminate\Http\UploadedFile) {
                $apiRequest->files->set($key, $value);
                continue;
            }

            $mergeParams[$key] = $value;
        }

        if ($mergeParams !== []) {
            $apiRequest->merge($mergeParams);
        }

        return $apiRequest;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeApiResponse(HttpResponse $response): array
    {
        $payload = json_decode($response->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    protected function apiMessage(array $payload, string $fallback = ''): string
    {
        $messages = $payload['messages'] ?? null;

        if (is_array($messages) && $messages !== []) {
            return implode(' ', array_map('strval', $messages));
        }

        if (isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== '') {
            return $payload['message'];
        }

        return $fallback;
    }

    protected function backWithApiSuccess(HttpResponse $response, string $fallback = 'Готово'): RedirectResponse
    {
        $payload = $this->decodeApiResponse($response);

        return back()->with('success', $this->apiMessage($payload, $fallback));
    }

    protected function backWithApiError(HttpResponse $response, string $fallback = 'Не удалось выполнить операцию'): RedirectResponse
    {
        $payload = $this->decodeApiResponse($response);

        return back()->with('error', $this->apiMessage($payload, $fallback));
    }
}