<?php

namespace App\Http\Traits;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Exception\ClientException;

trait FusionBrainTrait
{

    private $URL = 'https://api-key.fusionbrain.ai/';

    public function get_model()
    {
        $httpClient = new Client([
            'base_uri' => $this->URL,
            'headers' => [
                'X-Key' => 'Key ' . env('APP_KANDINSKY_KEY'),
                'X-Secret' => 'Secret ' . env('APP_KANDINSKY_SECRET_KEY'),
            ],
        ]);

        $response = $httpClient->get('key/api/v1/models');

        if (in_array($response->getStatusCode(), [200, 201, 204])) {
            $resp = json_decode($response->getBody(), true);
            $data = $resp[0]['id'];
            return $data;
        } else {
            return false;
        }
    }

    private function generate($prompt, $model, $images = 1, $width = 1024, $height = 1024)
    {
        $httpClient = new Client([
            'base_uri' => $this->URL,
            'headers' => [
                'X-Key' => 'Key ' . env('APP_KANDINSKY_KEY'),
                'X-Secret' => 'Secret ' . env('APP_KANDINSKY_SECRET_KEY'),
            ],
        ]);

        $params = [
            "type" => "GENERATE",
            "numImages" => $images,
            "width" => $width,
            "height" => $height,
            "generateParams" => [
                "query" => $prompt
            ]
        ];


        try {
            $response = $httpClient->post('key/api/v1/text2image/run', [
                'multipart' => [
                    [
                        'name' => 'model_id',
                        'contents' => $model,
                    ],
                    [
                        'name' => 'params',
                        'contents' => json_encode($params),
                        'headers' => ['Content-Type' => 'application/json']
                    ]
                ]
            ]);

            if (in_array($response->getStatusCode(), [200, 201, 204])) {
                $resp = json_decode($response->getBody(), true);
                $data = $resp['uuid'];
                return $data;
            }
        } catch (ClientException $e) {
            $response = $e->getResponse();
            return false;
        }

    }

    public function check_generation($request_id, $attempts = 10, $delay = 5)
    {
        while ($attempts > 0) {
            $httpClient = new Client([
                'base_uri' => $this->URL,
                'headers' => [
                    'X-Key' => 'Key ' . env('APP_KANDINSKY_KEY'),
                    'X-Secret' => 'Secret ' . env('APP_KANDINSKY_SECRET_KEY'),
                ],
            ]);

            $response = $httpClient->get('key/api/v1/text2image/status/' . $request_id);

            if (in_array($response->getStatusCode(), [200, 201, 204])) {
                $resp = json_decode($response->getBody(), true);
                if ($resp['status'] == 'DONE')
                    return $resp['images'];
            }
            $attempts--;
            sleep($delay);
            return $this->check_generation($request_id);
        }
        return false;
    }

}
