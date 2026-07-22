<?php

namespace App\Http\Traits;

use App\Http\Traits\GuzzleTrait;
use Illuminate\Support\Facades\Log;

trait WBFeedbacksTrait
{

    use GuzzleTrait;

    private function apiGetFeedbacks($apiKey, $data = array())
    {
        $isAnswered = array_key_exists('isAnswered', $data)
            ? filter_var($data['isAnswered'], FILTER_VALIDATE_BOOLEAN)
            : false;

        $params = [
            // WB expects boolean-like query values; strings are safest for Guzzle.
            'isAnswered' => $isAnswered ? 'true' : 'false',
            'take' => min(5000, max(1, (int) ($data['take'] ?? 10))),
            'skip' => max(0, (int) ($data['skip'] ?? 0)),
            'order' => $data['order'] ?? 'dateDesc',
        ];

        // Official WB Feedbacks API supports exact product filter by nmId.
        if (! empty($data['nmId'])) {
            $params['nmId'] = (int) $data['nmId'];
        }

        $url = 'https://feedbacks-api.wildberries.ru/api/v1/feedbacks';

        $result = $this->getRequest($url, $apiKey, $params);

        return ['data' => $result, 'function' => 'apiGetFeedbacks'];
    }

    private function apiPostAnswer($apiKey, $data = array())
    {

        $params = [
            'id' => $data['id'],
            'text' => $data['text']
        ];

        $url = 'https://feedbacks-api.wildberries.ru/api/v1/feedbacks/answer';

        $result = $this->postRequest($url, $apiKey, $params);

        return ['data' => $result, 'function' => 'apiPostAnswer'];
    }

    /**
     * Количество неотвеченных отзывов за всё время.
     * @see https://dev.wildberries.ru/docs/openapi/user-communication
     * GET /api/v1/feedbacks/count-unanswered
     */
    private function apiFeedbacksCountUnanswered($apiKey, $data = array())
    {
        $url = 'https://feedbacks-api.wildberries.ru/api/v1/feedbacks/count-unanswered';

        $result = $this->getRequest($url, $apiKey);

        return ['data' => $result, 'function' => 'apiFeedbacksCountUnanswered'];
    }

    /**
     * Download all unanswered feedbacks via skip/take pages (same flow as panel UI).
     * No dateFrom/dateTo — full unanswered history within safety cap.
     *
     * @param  array{id?: int|string, name?: string}|null  $clientContext  for error logs
     * @return array{
     *     success: bool,
     *     message?: string,
     *     code?: int|null,
     *     feedbacks: list<array<string, mixed>>,
     *     pages: int,
     *     wb_count_unanswered: ?int,
     *     truncated: bool
     * }
     */
    protected function fetchAllUnansweredFeedbacks(string $apiKey, ?int $nmId = null, ?array $clientContext = null): array
    {
        $pageSize = 1000;
        $maxItems = 25000;

        $countResult = $this->resolveUnansweredCount($apiKey, $clientContext);

        if (($countResult['code'] ?? null) === 401) {
            return [
                'success' => false,
                'message' => 'Не удаётся авторизоваться с указаным API ключом',
                'code' => 401,
                'feedbacks' => [],
                'pages' => 0,
                'wb_count_unanswered' => null,
                'truncated' => false,
            ];
        }

        $wbCount = $countResult['total'];
        $skip = 0;
        $pages = 0;
        $all = [];
        $truncated = false;

        while (count($all) < $maxItems) {
            $params = [
                'isAnswered' => false,
                'take' => $pageSize,
                'skip' => $skip,
                'order' => 'dateDesc',
            ];
            if ($nmId) {
                $params['nmId'] = $nmId;
            }

            $data = $this->parseApiResponse($this->apiGetFeedbacks($apiKey, $params), $clientContext);

            if (! $data['success']) {
                if ($pages === 0) {
                    $code = $data['code'] ?? null;
                    $message = $code === 401
                        ? 'Не удаётся авторизоваться с указаным API ключом'
                        : $this->extractWbFeedbacksErrorMessage($data['data'] ?? null);

                    return [
                        'success' => false,
                        'message' => $message,
                        'code' => $code,
                        'feedbacks' => [],
                        'pages' => 0,
                        'wb_count_unanswered' => $wbCount,
                        'truncated' => false,
                    ];
                }

                $truncated = true;
                break;
            }

            if (is_array($data['data']) && ! empty($data['data']['error'])) {
                if ($pages === 0) {
                    return [
                        'success' => false,
                        'message' => $this->extractWbFeedbacksErrorMessage($data['data']),
                        'code' => $data['code'] ?? 400,
                        'feedbacks' => [],
                        'pages' => 0,
                        'wb_count_unanswered' => $wbCount,
                        'truncated' => false,
                    ];
                }
                $truncated = true;
                break;
            }

            $payload = $data['data']['data'] ?? [];
            $pageItems = is_array($payload['feedbacks'] ?? null) ? $payload['feedbacks'] : [];
            $pages++;

            foreach ($pageItems as $item) {
                $all[] = $item;
                if (count($all) >= $maxItems) {
                    $truncated = true;
                    break 2;
                }
            }

            if (count($pageItems) < $pageSize) {
                break;
            }

            $skip += $pageSize;

            if ($wbCount !== null && $skip >= $wbCount) {
                break;
            }
        }

        if ($wbCount !== null && count($all) < $wbCount) {
            $truncated = true;
        }

        return [
            'success' => true,
            'code' => 200,
            'feedbacks' => $all,
            'pages' => $pages,
            'wb_count_unanswered' => $wbCount,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array{id?: int|string, name?: string}|null  $clientContext
     * @return array{success: bool, code: ?int, total: ?int}
     */
    protected function resolveUnansweredCount(string $apiKey, ?array $clientContext = null): array
    {
        $count = $this->parseApiResponse($this->apiFeedbacksCountUnanswered($apiKey), $clientContext);

        if (! $count['success']) {
            return [
                'success' => false,
                'code' => $count['code'] ?? null,
                'total' => null,
            ];
        }

        return [
            'success' => true,
            'code' => 200,
            'total' => $this->extractCountUnansweredPayload($count['data'] ?? null),
        ];
    }

    /**
     * WB wraps payloads as { data: { countUnanswered: N } } (sometimes nested).
     */
    protected function extractCountUnansweredPayload(mixed $payload): ?int
    {
        if (! is_array($payload)) {
            return null;
        }

        $candidates = [
            data_get($payload, 'data.countUnanswered'),
            data_get($payload, 'countUnanswered'),
            data_get($payload, 'data.data.countUnanswered'),
        ];

        foreach ($candidates as $value) {
            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return null;
    }

    protected function extractWbFeedbacksErrorMessage(mixed $payload): string
    {
        if (is_array($payload)) {
            if (! empty($payload['errorText'])) {
                return (string) $payload['errorText'];
            }
            if (! empty($payload['error'])) {
                return (string) $payload['error'];
            }

            return 'Ошибка при обращении к API Wildberries';
        }

        return is_string($payload) && $payload !== ''
            ? $payload
            : 'Ошибка при обращении к API Wildberries';
    }

    private function parseApiResponse($resp, $client = null)
    {
        if (!$resp['data']) {
            $success = false;
            $code = 503;
            $data = 'Ошибка доступа к API. Функция: ' . $resp['function'];
        } else {
            switch ($resp['data']["code"]) {
                case 200:
                    $success = true;
                    $code = 200;
                    $data = json_decode($resp['data']["response"], true);
                    break;
                case 400:
                    $success = false;
                    $code = 400;
                    $data = json_decode($resp['data']["response"], true);
                    break;
                case 401:
                    $success = false;
                    $code = 401;
                    $data = json_decode($resp['data']["response"], true);
                    break;
                case 422:
                    $success = false;
                    $code = 422;
                    $data = $resp['data']["response"];
                    break;
                case 429:
                    $success = false;
                    $code = 429;
                    $data = 'Превышен лимит запросов';
                    break;
                case 204:
                    $success = true;
                    $code = 204;
                    $data = [];
                    break;
                default:
                    $success = false;
                    $code = 503;
                    $data = 'Неизвестная ошибка API';
                    break;
            }
        }

        if (!$success) {
            $message = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($client)
                $message .= " | Клиент: " . $client['id'] . ", " . $client['name'];
            Log::channel('wb_api_response')->info('Код ответа: ' . $code . '. Данные: ' . $message);
        }

        return ['success' => $success, 'code' => $code, 'data' => $data];
    }

    private function volFeedbackHost($nmId)
    {
        $nm = (int) $nmId;
        $vol = floor($nm / 1e5);
        $part = floor($nm / 1e3);
        $host = "";

        if ($vol >= 0 && $vol <= 431) {
            $host = "//feedback01.wbcontent.net";
        } elseif ($vol >= 432 && $vol <= 863) {
            $host = "//feedback02.wbcontent.net";
        } elseif ($vol >= 864 && $vol <= 1199) {
            $host = "//feedback03.wbcontent.net";
        } elseif ($vol >= 1200 && $vol <= 1535) {
            $host = "//feedback04.wbcontent.net";
        } elseif ($vol >= 1536 && $vol <= 1919) {
            $host = "//feedback05.wbcontent.net";
        } elseif ($vol >= 1920 && $vol <= 2303) {
            $host = "//feedback06.wbcontent.net";
        } elseif ($vol >= 2304 && $vol <= 2687) {
            $host = "//feedback07.wbcontent.net";
        } elseif ($vol >= 2688 && $vol <= 3071) {
            $host = "//feedback08.wbcontent.net";
        } elseif ($vol >= 3072 && $vol <= 3455) {
            $host = "//feedback09.wbcontent.net";
        } elseif ($vol >= 3456 && $vol <= 3839) {
            $host = "//feedback10.wbcontent.net";
        } elseif ($vol >= 3840 && $vol <= 4607) {
            $host = "//feedback11.wbcontent.net";
        } else {
            $host = "//feedback12.wbcontent.net";
        }

        return $host . "/vol" . $vol . "/part" . $part . "/" . $nm;
    }
}
