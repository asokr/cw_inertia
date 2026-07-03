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
     * @param  array<string, mixed>  $params
     */
    protected function apiRequestWith(Request $request, array $params): Request
    {
        return $request->duplicate($params, $params);
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

    protected function redirectWithApiError(string $to, HttpResponse $response, string $fallback = 'Не удалось выполнить операцию'): RedirectResponse
    {
        $payload = $this->decodeApiResponse($response);

        return redirect()->to($to)->with('error', $this->apiMessage($payload, $fallback));
    }

    protected function redirectWithApiSuccess(string $to, HttpResponse $response, string $fallback = 'Готово'): RedirectResponse
    {
        $payload = $this->decodeApiResponse($response);

        return redirect()->to($to)->with('success', $this->apiMessage($payload, $fallback));
    }
}