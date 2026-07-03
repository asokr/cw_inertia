<?php

namespace App\Services\Admin;

use App\Models\AiRequestLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class AdminAiMarketplaceLogService
{
    public function paginate(array $filters, int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        $query = AiRequestLog::query()->orderByDesc('created_at');

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['date_to']);
        }

        if (! empty($filters['task_type'])) {
            $query->where('task_type', (string) $filters['task_type']);
        }

        if (! empty($filters['status_code'])) {
            $query->where('status_code', (int) $filters['status_code']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($subQuery) use ($search) {
                $subQuery
                    ->where('user_id', 'like', '%' . $search . '%')
                    ->orWhere('subscriber_id', 'like', '%' . $search . '%');
            });
        }

        $logs = $query->paginate($perPage, ['*'], 'page', $page);

        $logs->getCollection()->transform(fn (AiRequestLog $log) => $this->formatLog($log));

        return $logs;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatLog(AiRequestLog $log): array
    {
        $payload = $log->request_payload;
        $payloadArray = is_array($payload) ? $payload : [];
        $responseText = trim((string) ($log->response_text ?? ''));
        $responseImagesRaw = is_array($log->response_images) ? $log->response_images : [];
        $responseVideosRaw = is_array($log->response_videos) ? $log->response_videos : [];

        $responseVideos = collect($responseVideosRaw)->map(function ($video) {
            if (! is_array($video)) {
                return null;
            }

            $path = trim((string) ($video['path'] ?? ''));
            if ($path === '') {
                return $video;
            }

            $internalUrl = $this->buildMediaUrl($path);

            return array_merge($video, [
                'url' => $internalUrl,
                'url_preview' => $internalUrl,
            ]);
        })->filter()->values()->all();

        if ($responseVideos === []) {
            $responseVideos = null;
        }

        $responseImages = collect($responseImagesRaw)->map(function ($image) {
            if (! is_array($image)) {
                return null;
            }

            $path = trim((string) ($image['path'] ?? ''));
            $mimeType = (string) ($image['mime_type'] ?? 'image/png');

            if ($path !== '') {
                $internalUrl = $this->buildMediaUrl($path);

                return [
                    'mime_type' => $mimeType,
                    'path' => $path,
                    'url_preview' => $internalUrl,
                    'url' => $internalUrl,
                ];
            }

            $base64 = (string) ($image['base64'] ?? '');
            if ($base64 === '') {
                return null;
            }

            return [
                'mime_type' => $mimeType,
                'base64' => $base64,
                'data_uri' => 'data:' . $mimeType . ';base64,' . $base64,
            ];
        })->filter()->values()->all();

        if ($responseImages === []) {
            $responseImages = null;
        }

        $providerRequestPayload = null;
        $geminiRequestPayload = is_array($payloadArray['_gemini_request_payload'] ?? null)
            ? $payloadArray['_gemini_request_payload']
            : null;
        $grokRequestPayload = is_array($payloadArray['_grok_request_payload'] ?? null)
            ? $payloadArray['_grok_request_payload']
            : null;

        if (is_array($grokRequestPayload)) {
            $providerRequestPayload = $grokRequestPayload;
        } elseif (is_array($geminiRequestPayload)) {
            $providerRequestPayload = $geminiRequestPayload;
        }

        if (is_array($payloadArray) && array_key_exists('_gemini_request_payload', $payloadArray)) {
            unset($payloadArray['_gemini_request_payload']);
        }

        if (is_array($payloadArray) && array_key_exists('_grok_request_payload', $payloadArray)) {
            unset($payloadArray['_grok_request_payload']);
        }

        $incomingPayloadString = is_array($payload)
            ? json_encode($payloadArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) ($payload ?? '');

        $providerPayloadString = is_array($providerRequestPayload)
            ? json_encode($providerRequestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        $providerResponsePayload = is_array($log->provider_response_payload)
            ? $log->provider_response_payload
            : null;

        $providerResponsePayloadString = is_array($providerResponsePayload)
            ? json_encode($providerResponsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        return [
            'id' => $log->id,
            'created_at' => optional($log->created_at)->format('Y-m-d H:i:s'),
            'task_type' => $log->task_type,
            'marketplace' => $log->marketplace,
            'provider' => $log->provider,
            'model' => $log->model,
            'status_code' => $log->status_code,
            'user_id' => $log->user_id,
            'subscriber_id' => $log->subscriber_id,
            'response_type' => $log->response_type,
            'generation_status' => $log->generation_status,
            'external_request_id' => $log->external_request_id,
            'images_count' => $log->images_count,
            'videos_count' => $log->videos_count,
            'input_tokens' => $log->input_tokens,
            'output_tokens' => $log->output_tokens,
            'prompt_tokens' => $log->prompt_tokens,
            'candidates_tokens' => $log->candidates_tokens,
            'total_tokens' => $log->total_tokens,
            'error_message' => $log->error_message,
            'response_text_full' => $responseText,
            'response_text_preview' => Str::limit($responseText, 180),
            'response_images' => $responseImages,
            'response_videos' => $responseVideos,
            'request_payload_full' => $incomingPayloadString,
            'request_payload_preview' => Str::limit($incomingPayloadString, 350),
            'provider_request_payload_full' => $providerPayloadString,
            'provider_request_payload_preview' => Str::limit($providerPayloadString, 350),
            'provider_response_payload_full' => $providerResponsePayloadString,
            'provider_response_payload_preview' => Str::limit($providerResponsePayloadString, 350),
        ];
    }

    private function buildMediaUrl(string $path): string
    {
        return '/cw-page/services/ai/media/' . $this->encodePathForRoute($path);
    }

    private function encodePathForRoute(string $path): string
    {
        $segments = array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== '');

        return implode('/', array_map(static fn (string $segment): string => rawurlencode($segment), $segments));
    }
}