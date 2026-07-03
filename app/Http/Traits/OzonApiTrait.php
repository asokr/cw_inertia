<?php

namespace App\Http\Traits;

use GuzzleHttp;
use Illuminate\Support\Facades\Log;

trait OzonApiTrait
{
    private $URL = 'https://api-seller.ozon.ru/';

    private function buildClientLogContext($client = null): array
    {
        if (!$client) {
            return [];
        }

        if (is_array($client)) {
            return [
                'user_id' => $client['user_id'] ?? null,
                'cabinet_id' => $client['id'] ?? ($client['cabinet_id'] ?? null),
                'cabinet_name' => $client['name'] ?? ($client['cabinet_name'] ?? null),
            ];
        }

        return [
            'user_id' => $client->user_id ?? null,
            'cabinet_id' => $client->id ?? null,
            'cabinet_name' => $client->name ?? null,
        ];
    }

    public function getReviewList($apiKey, $clientId, $params = [], $filters = [])
    {
        $url = 'v2/review/list';

        if (!empty($filters)) {
            $params['filters'] = $filters;
        }

        $result = $this->postRequest($url, $apiKey, $clientId, $params);

        return ['data' => $result, 'function' => 'getReviewList'];
    }

    public function сountUnanswered($apiKey, $clientId)
    {
        $url = 'v1/review/count';

        $result = $this->postRequest($url, $apiKey, $clientId);

        return ['data' => $result, 'function' => 'сountUnanswered'];
    }

    // Оставить комментарий на отзыв

    public function reviewAnswer($apiKey, $clientId, $params = [])
    {
        $url = 'v1/review/comment/create';

        $result = $this->postRequest($url, $apiKey, $clientId, $params);

        return ['data' => $result, 'function' => 'reviewAnswer'];
    }

    public function getProductInfo($apiKey, $clientId, $params = [])
    {
        $url = 'v3/product/info/list';

        $result = $this->postRequest($url, $apiKey, $clientId, $params);

        return ['data' => $result, 'function' => 'getProductInfo'];
    }


    private function parseApiResponse($resp, $client = null)
    {
        $data = [];

        if (!$resp['data']) {
            $success = false;
            $code = 503;
            $data['message'] = 'Ошибка доступа к API. Функция: ' . $resp['function'];
        } else {
            switch ($resp['data']["code"]) {
                case 200:
                    $data = json_decode($resp['data']["response"], true);
                    if (isset($data['code'])) {
                        $success = false;
                        $code = $data['code'];
                    } else {
                        $success = true;
                        $code = 200;
                    }
                    break;
                case 400:
                    $data = json_decode($resp['data']["response"], true);
                    $success = false;
                    $code = $data['code'];
                    break;
                case 403:
                    $data = json_decode($resp['data']["response"], true);
                    $success = false;
                    $code = $data['code'];
                    switch ($code) {
                        case 7:
                            $data['message'] = 'Не хватает прав для API ключа';
                            break;
                    }
                    break;
                case 404:
                    $data = json_decode($resp['data']["response"], true);
                    $success = false;
                    $code = $data['code'];
                    switch ($code) {
                        case 5:
                            $data['message'] = 'Не верный ключ API или ClientId';
                            break;
                    }
                    break;
                default:
                    Log::channel('oz_api_response')->info(json_encode($resp['data'], JSON_UNESCAPED_UNICODE));
                    $success = false;
                    $code = 503;
                    $data['message'] = 'Неизвестная ошибка API';
                    break;
            }
        }

        if (!$success) {
            $context = $this->buildClientLogContext($client);
            $context['function'] = $resp['function'] ?? null;
            $context['status_code'] = $resp['data']['code'] ?? null;
            $context['ozon_response_raw'] = $resp['data']['response'] ?? null;
            $context['ozon_response_json'] = is_array($data) ? $data : null;

            Log::channel('oz_api_response')->info(
                'Код ошибки: ' . $code . '. Сообщение: ' . ($data['message'] ?? 'Неизвестная ошибка API'),
                $context
            );
        }

        return ['success' => $success, 'code' => $code, 'data' => $data];
    }

    private function putRequest($url, $apiKey, $clientId, $data = array())
    {

        $headers = [
            'accept' => 'application/json',
            'Content-Encoding' => 'Accept-Encoding: gzip, deflate, br',
            'Api-Key' => $apiKey,
            'Client-Id' => $clientId
        ];

        $client = new GuzzleHttp\Client([
            'base_uri' => $this->URL,
            'headers' => $headers,
            'http_errors' => false
        ]);

        $response = $client->put($url, ['json' => $data]);

        if (in_array($response->getStatusCode(), [200, 204, 400, 401, 422, 429])) {
            return array(
                "headers" => $response->getHeaders(),
                "response" => $response->getBody()->getContents(),
                "code" => $response->getStatusCode()
            );
        } else {
            return false;
        }
    }

    private function patchRequest($url, $apiKey, $clientId, $data = array())
    {

        $headers = [
            'accept' => 'application/json',
            'Content-Encoding' => 'Accept-Encoding: gzip, deflate, br',
            'Api-Key' => $apiKey,
            'Client-Id' => $clientId
        ];

        $client = new GuzzleHttp\Client([
            'base_uri' => $this->URL,
            'headers' => $headers,
            'http_errors' => false
        ]);

        $response = $client->patch($url, ['json' => $data]);

        if (in_array($response->getStatusCode(), [200, 204, 400, 401, 422, 429])) {
            return array(
                "headers" => $response->getHeaders(),
                "response" => $response->getBody()->getContents(),
                "code" => $response->getStatusCode()
            );
        } else {
            return false;
        }
    }

    private function getRequest($url, $apiKey, $clientId, $data = array(), $function = '')
    {

        $headers = [
            'accept' => 'application/json',
            'Content-Encoding' => 'Accept-Encoding: gzip, deflate, br',
            'Api-Key' => $apiKey,
            'Client-Id' => $clientId
        ];


        $client = new GuzzleHttp\Client([
            'base_uri' => $this->URL,
            'headers' => $headers,
            'http_errors' => false
        ]);

        $response = $client->get($url, [
            'query' => $data
        ]);

        if (in_array($response->getStatusCode(), [200, 204, 400, 401, 422, 429])) {
            return array(
                "headers" => $response->getHeaders(),
                "response" => $response->getBody()->getContents(),
                "code" => $response->getStatusCode()
            );
        } else {

            return false;
        }
    }

    private function postRequest($url, $apiKey, $clientId, $data = array(), $function = '')
    {
        $headers = [
            'accept' => 'application/json',
            'Content-Encoding' => 'Accept-Encoding: gzip, deflate, br',
            'Api-Key' => $apiKey,
            'Client-Id' => $clientId
        ];

        $client = new GuzzleHttp\Client([
            'base_uri' => $this->URL,
            'headers' => $headers,
            'http_errors' => false
        ]);

        if (!empty($data)) {
            $params = array(
                'json' => $data
            );
            $response = $client->post($url, $params);
        } else {
            $response = $client->post($url);
        }


        if (in_array($response->getStatusCode(), [200, 204, 400, 401, 404, 422, 429])) {
            return array(
                "headers" => $response->getHeaders(),
                "response" => $response->getBody()->getContents(),
                "code" => $response->getStatusCode()
            );
        } else {
            return false;
        }
    }
}
