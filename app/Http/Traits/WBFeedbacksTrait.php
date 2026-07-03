<?php

namespace App\Http\Traits;

use App\Http\Traits\GuzzleTrait;
use Illuminate\Support\Facades\Log;

trait WBFeedbacksTrait
{

    use GuzzleTrait;

    private function apiGetFeedbacks($apiKey, $data = array())
    {

        $params = [
            'isAnswered' => $data['isAnswered'] ?? false,
            'take' => min(5000, (int) ($data['take'] ?? 10)),
            'skip' => $data['skip'] ?? 0,
            'order' => $data['order'] ?? 'dateDesc',
        ];

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

    private function apiFeedbacksCountUnanswered($apiKey, $data = array())
    {

        $url = 'https://feedbacks-api.wildberries.ru/api/v1/feedbacks';

        $result = $this->getRequest($url, $apiKey);

        return ['data' => $result, 'function' => 'apiFeedbacksCountUnanswered'];
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
